# Indexing Nextcloud Talk chat histories

EVA can answer out of a Nextcloud Talk conversation - but only when it is
addressed, and with two different views of the conversation.

## Live context and indexed history

| | What it is | Where it comes from |
| --- | --- | --- |
| **Live context** | The last `talk_history_size` messages of the room the question was asked in | Read on the spot from Talk |
| **Indexed history** | The older parts of that room's conversation, retrievable by meaning | Built by the indexing pass, described here |

The live context follows a conversation; the index is what makes *"what did we
decide about the budget last month?"* answerable at all.

**The bot never starts a conversation.** It reacts to a message only when that
message addresses it: an `@` mention of the bot, or the configured trigger word
(`talk_bot_trigger`, default `Eva`). Messages that do not address it are neither
answered nor used to trigger anything - they are only read when the room is
indexed. The decision is made per message, and a mention inside a code block or a
quoted line does not count.

## The pipeline

```
Talk rooms of the user ──▶ transcript per room ──▶ one document per room ──▶ chunks + embeddings
   (membership now)         (newest N messages)      talk://<roomId>            (searchable)
```

1. **Rooms come from Talk, at index time.** `TalkTranscriptService::roomsForUser()`
   asks Talk's own manager for the rooms the user is a participant of. A room the
   user has left simply stops appearing, and the next pass reconciles it away -
   there is no stored membership list to go stale.
2. **A room becomes readable text.** The newest `talk_index_max_messages`
   (default 200) messages are read, oldest first, and formatted as
   `[2026-09-11 08:08] admin: message text` under a `Talk chat: <room name>`
   header. Talk's machinery is dropped: changelog entries, bot entries, command
   text (`/me …`), and JSON system payloads such as `conversation_created`.
3. **One document per room.** It is stored like any other indexed document but
   under a synthetic identity: path `talk://<roomId>`, source `talk`, and a file
   id in a reserved negative range (`Indexer::talkFileId()`). The range is
   reserved because Talk rooms and mail messages are unrelated sequences that
   both start at 1 - without the split, a mail cleanup would delete chat rooms
   (see `docs/ARCHITECTURE.md`).
4. **Answering.** When the bot is addressed, it retrieves the passages of *that
   room* that match the question, so one room's history can never surface in
   another. Membership is checked again at answer time, and an unverifiable
   membership fails closed: leaving a room stops its history being quoted
   immediately, not at the next indexing pass.

## What is deliberately *not* indexed

- **EVA's own answers.** Bot messages are dropped as machinery. Indexing them
  would double the index and let the bot quote itself as a source.
- **System and changelog messages**, commands and JSON payloads (see above).
- **Attachments, photos and file previews** shared in a chat. Only the message
  text is read; a conversation *about* a file is indexed, the file itself is not
  (the file index covers that, if the user has it).
- **Messages beyond the budget.** A room with more history than
  `talk_index_max_messages` is indexed from the newest messages back; older ones
  are dropped first, because recent context is the useful part.
- **Rooms of other users.** Indexing runs per user and stores per user. Two
  members of the same room each get their own copy, and each can only retrieve
  their own.

## When it runs

| Trigger | Effect |
| --- | --- |
| `talk_index_enabled` (personal setting, default **off**) | Include Talk rooms in the automatic indexing pass |
| The "Only index Nextcloud Talk chats" button (`POST /api/talkIndex`) | Run a Talk-only pass now, regardless of the switch |
| `occ eva_ai:talk <user> --index` | The same pass from the shell |

The switch is off by default because a chat log is the most personal content an
instance holds. The button exists so that trying the feature does not require
turning the automatic pass on for everything.

## Verifying it works

```bash
# What Talk has, what EVA can read from it, and what is stored - per room.
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:talk admin

# Run the pass now (ignores the switch, like the button)
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:talk admin --index

# Prove that the indexed history is actually recallable
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:talk admin --room 4 --ask "Was war das Budget?"
```

A healthy run looks like this (real output, instance with four rooms):

```
Talk indexing
  Talk:            available
  Index switch:    talk_index_enabled = on
  Rooms per pass:  20
  Messages/room:   200
  Trigger word:    Eva

Rooms of admin: 4 (limit 20)
  room 1    Talk updates ✅
    readable:      no usable messages
    in the index:  no
  room 2    Notiz an mich
    readable:      no usable messages
    in the index:  no
  room 3    Los geht's!
    readable:      9 messages, 2421 characters
    in the index:  yes, 64-char fingerprint, 3 chunks
  room 4    AI
    readable:      8 messages, 493 characters
    in the index:  yes, 64-char fingerprint, 1 chunks

Rooms with usable content: 2 of 4
```

Room 1 is Talk's own changelog room and room 2 holds only the
"conversation created" line: both correctly read as *no usable messages* and get
no document, rather than being stored as empty rooms.

`--room` with `--ask` separates the two reasons an empty result can have, which
is the difference between a permission decision and a broken index:

```
  nothing recalled - the room may not be indexed yet, or the embeddings are unavailable
  refused: admin is not a member of room 4
```

## Reading and writing a chat on request

Indexing makes a conversation *searchable*. That is not the same as reading it:
when the user asks "what did we agree in the project room?", the answer has to
come from the conversation as it is now, not from a snapshot taken when the
index was last built. The live path is therefore separate from the index, and it
is the same path whether the question arrives in the web chat or in the
Assistant.

| Tool | What it does | Available when |
| --- | --- | --- |
| `list_talk_rooms` | The conversations the user is a member of, most recently active first (name, token, id, type) | Talk is installed |
| `read_talk_chat` | The recent messages of one room, oldest first, dated and attributed | Talk is installed |
| `send_talk_message` | Posts a message into one of those rooms **as the signed-in user** | The user switched `talk_write_enabled` on |

```
question ──▶ list_talk_rooms ──▶ the user's own rooms ──▶ resolve "the project room"
                                          │
                     read_talk_chat ◀──────┴──────▶ send_talk_message
              (comments table, membership         (Talk's ChatManager, actor = the
               re-checked at read time)             asking user, never a bot label)
```

1. **A room reference is resolved against the user's own room list.** A name, a
token or a numeric id from the model is a *reference*: it is matched against the
rooms Talk says this user is in (`TalkChatService::resolveRoom()`), never used to
look a room up directly. A prompt naming somebody else's conversation therefore
resolves to nothing instead of to their messages, and an ambiguous name returns
the candidates rather than picking one.
2. **Reading re-checks membership at read time.** `read_talk_chat` goes through
the same transcript path the indexer uses, which asks Talk again and fails closed
when the membership cannot be verified: leaving a room stops its history being
quoted immediately.
3. **Posting is an act in the user's name, so it is opt-in and confirmed.** The
message is sent through Talk's own `ChatManager` with the user as the author
(`Attendee::ACTOR_USERS`), so it appears exactly as if they had typed it - no bot
badge, and Talk applies its own mention and rate-limit handling. The switch
`talk_write_enabled` is per user and off by default, and the tool is not offered
at all on the Talk surface itself: there the bot would be posting into the room
as the very person who just asked it a question.
4. **The prompt rules match the policy.** The system prompt only describes
`send_talk_message` once the user has switched it on, and it tells the model to
read a conversation only when it is part of the question. Chat content is
treated as untrusted data, never as instructions.

## Verifying reading and posting by hand

```bash
# Which rooms does EVA see for this user, with their tokens?
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:tool admin list_talk_rooms '{}'

# Read the current messages of a room (name, token or id)
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:tool admin read_talk_chat '{"room":"AI","limit":20}'

# Post as the user - only works once talk_write_enabled is on for them
sudo -u www-data php /var/www/html/nextcloud/occ eva_ai:tool admin send_talk_message \
  '{"room":"AI","message":"Die Wartung beginnt um 18:00."}'
```

With the switch off, the third command answers
`Posting to Nextcloud Talk is switched off.` and nothing is written - that is the
intended state, not a failure. `send_talk_message` is also gated at the policy
boundary (`ToolPolicy`), so the tool disappears from every surface and is skipped
in the agent proposal phase while it is disabled.

## Failure modes worth knowing

| Symptom | Cause and what to do |
| --- | --- |
| `readable: no usable messages` for a room that clearly has content | Every message in it is machinery (changelog, bot, system). Check the room in Talk. |
| `in the index: no` while `readable` shows messages | The Talk pass has not run since change. Press the button or run `--index`. |
| Nothing recalled, but the room is indexed | The question embeddings are unavailable (Ollama unreachable) - that breaks file search the same way. Check `occ eva_ai:status` or the admin page. |
| `Indexing is already running for this user.` | A run holds the claim. If its worker is gone (a killed process, a fatal error), the claim is released automatically once its heartbeat is older than 15 minutes - see below. |
| Rooms appear only as `talk://<id>` in the documents list | That is the stored path; the document name (`Talk: <room name>`) is what the UI shows next to it. |

### Two failures that made Talk indexing silently useless

Both were found on a live instance and are fixed; they are documented because
they explain the shape of the code.

1. **A room was read through an API that refuses long messages.** The transcript
   was built from Nextcloud's Comments API, which validates a comment's message
   against a 1000-character limit *while reading it*. Talk accepts much longer
   messages, and EVA's own answers are longer than that - so one long message
   made the whole room throw `MessageTooLongException`, and the room was skipped
   without an error. The rows are therefore read directly from the table Talk
   writes them to, with the same filters the API path had. `tests/TalkIndexingTest`
   keeps this case as a guarantee.
2. **A configuration read that could never work.** The message budget was read
   from a property that does not exist (`$this->config` instead of
   `$this->appConfig`), so *every* transcript threw before any room was read. The
   log said so on every pass - `Undefined property:
   TalkTranscriptService::$config` - and the indexing reported "processed: 0"
   without an error. Only the formatting had been tested, never the read path;
   the tests now cover both.

### A run claim that is never released

A worker that dies mid-run (killed process, fatal error, reboot) leaves its run
claimed, and every later pass then answers "already running" while nothing is
running at all. The rule that decides when such a claim is abandoned lives in
exactly one place - `AppConfig::recoverAbandonedRun()`, heartbeat older than
`STALE_RUN_SECONDS` (15 min), or `CANCEL_GRACE_SECONDS` (5 min) while a stop was
requested - and is used by every entry point: the API controller, the status
endpoint, the cron job and the indexer itself. It used to be written out four
times, which is how a run could be recovered by one caller and still block
another.
