# Cloudflare AI Workers 🔗 Nextcloud Assistant

I made this script mainly targeted for nextcloud assistant because cloudflare provides Ai endpoints as openai API format for a good number of requests (almost for free) so why not use it instead of hosting our own instance of AI.

## Issue with directly using the cloudflare provided OpenAI API

When you try to use the Cloudflare provided OpenAI API directly, you might encounter an issue. For example, if you make the following request:

```bash
curl  https://api.cloudflare.com/client/v4/accounts/{accountid}/ai/v1/models \
  -H  "Authorization: Bearer XXXXXXXXXXXXXXXXXXXXXXXXXXX"
```

You might get a response like this:

```json
{"result":null,"success":false,"errors":[{"code":7001,"message":"GET not supported for requested URI."}],"messages":[]}
```

This is because Cloudflare doesn't provide `/v1/models` in their API. To solve this, I created this script which returns the list of models from the list specified in `main.py` and forwards the rest of the requests directly to the Cloudflare API.

## How to use

### 1. Install Nextcloud Assistant and OpenAI Integration

Make sure you have installed Nextcloud Assistant and OpenAI integration in your Nextcloud instance.

### 2. Get Account ID and API Key from Cloudflare

Obtain your account ID and API key from Cloudflare. You can find more information [here](https://developers.cloudflare.com/workers-ai/get-started/rest-api/#1-get-api-token-and-account-id).

### 3. Run the Docker Container

Run the following command to start the Docker container:

```bash
docker run -d -e ACCOUNTID=XXXXXXXXXXXX -p 5050:5050 ghcr.io/schBenedikt/nextcloud-assistant:main
```

Replace `XXXXXXXXXXXX` with the account ID obtained from Cloudflare.

### 4. Configure Nextcloud

- Go to administration settings -> Artificial intelligence
- Under OpenAI and LocalAI integration, change the following settings:
  - **Service URL**: `http://<machine-ip>:5050`
    - Replace `<machine-ip>` with the local IP address of the machine where the Docker container is running.
  - **API Key**: Paste the API key obtained from Cloudflare.

![Nextcloud AI Configuration](image.png)

Congratulations! Now you have AI integrated into your Nextcloud instance.

### Note

Beta models in Cloudflare AI don't count towards your request quota, but Cloudflare can move them to stable, so be careful. You can find all the models [here](https://developers.cloudflare.com/workers-ai/models/#text-generation).

### Contributions are welcome!

Contributions are most welcome, such as improving the documentation, adding new models to the list, etc.
