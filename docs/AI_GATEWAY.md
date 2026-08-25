# Hercule POS Cloud AI Gateway

The production endpoint is:

`POST /public/api/ai_chat.php`

The desktop app sends only the license identity, HWID, and the user's question for intent routing. Store database contents remain local to the desktop application.

## Azure App Service environment variables

Set these in **Azure Portal → App Service → Configuration / Environment variables**. Never commit real secrets to GitHub.

Required provider credentials:

- `GEMINI_API_KEY`
- `GROQ_API_KEY`
- `CLOUDFLARE_AI_TOKEN`
- `CLOUDFLARE_ACCOUNT_ID`

Default provider order:

`HERCULE_AI_PROVIDER_ORDER=gemini,groq,cloudflare`

Default models:

- `GEMINI_MODEL=gemini-2.5-flash-lite`
- `GROQ_MODEL=openai/gpt-oss-20b`
- `CLOUDFLARE_AI_MODEL=@cf/meta/llama-3.1-8b-instruct-fast`

Rate-limit defaults:

- `HERCULE_AI_PER_LICENSE_RPM=12`
- `HERCULE_AI_GLOBAL_RPM=30`
- `HERCULE_AI_IP_RPM=60`
- `HERCULE_AI_429_COOLDOWN_SEC=45`
- `HERCULE_AI_FAILURE_COOLDOWN_SEC=20`

## Security properties

- Provider API keys never reach Electron clients.
- The endpoint requires an active Hercule license and activated HWID.
- Blocked devices are rejected.
- Intent names are allow-listed by `public/api/intent_catalog.json`.
- Provider output cannot create arbitrary SQL or arbitrary actions.
- Per-IP, per-license, and global throttles protect free provider quotas.
- Provider failures trigger a short circuit-breaker cooldown and automatic fallback to the next configured provider.

## Expected request

```json
{
  "license_key": "XXXX-XXXX-XXXX-XXXX-XXXX",
  "hwid": "device-hwid",
  "question": "شكد المبيعات اليوم؟"
}
```

Successful routing response:

```json
{
  "ok": true,
  "provider": "gemini",
  "route": {
    "intent": "sales_summary",
    "entity_type": "none",
    "entity_query": ""
  }
}
```

The endpoint is a routing service, not a database execution service. The desktop application remains responsible for executing the approved local report handler and enforcing its own user permissions.
