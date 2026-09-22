# EVA AI 2.0 roadmap

The 2.0 milestone is intentionally tracked as a small set of follow-up issues.
The reliability, security, configuration, indexing, plugin, and release work
that was ready for this iteration has already landed on `main`. The remaining
items below need dedicated implementation slices so they can be reviewed and
validated independently.

## Remaining work

| Priority | Issue | Scope |
| --- | --- | --- |
| Critical | [#303](https://github.com/SchBenedikt/nextcloud-ai/issues/303) | Split `ApiController` into focused controllers while preserving OCS routes. |
| High | [#306](https://github.com/SchBenedikt/nextcloud-ai/issues/306) | Split `ActionExecutor` into domain-specific executors with shared policy checks. |
| High | [#309](https://github.com/SchBenedikt/nextcloud-ai/issues/309) | Introduce a typed request object for `RagService::ask()`. |
| Medium | [#318](https://github.com/SchBenedikt/nextcloud-ai/issues/318) | Add controller-level integration coverage against the real Nextcloud request stack. |
| Medium | [#313](https://github.com/SchBenedikt/nextcloud-ai/issues/313) | Extract Vue composables from `App.vue` without changing navigation behavior. |
| Medium | [#354](https://github.com/SchBenedikt/nextcloud-ai/issues/354) | Add request and response DTOs endpoint by endpoint. |
| Low | [#382](https://github.com/SchBenedikt/nextcloud-ai/issues/382) | Complete the responsive UX smoke audit across EVA surfaces. |
| Low | [#320](https://github.com/SchBenedikt/nextcloud-ai/issues/320) | Translate remaining legacy German code comments and add a maintainable check. |

## Delivery order

1. Controller boundaries and request validation (#303, #309).
2. Action execution boundaries and integration coverage (#306, #318).
3. Frontend composables and responsive audit (#313, #382).
4. Broad DTO rollout and comment cleanup (#354, #320).

Each issue should land with focused tests and a separate pull request. The 2.0
release should remain unpublished until this milestone reaches zero open issues.
