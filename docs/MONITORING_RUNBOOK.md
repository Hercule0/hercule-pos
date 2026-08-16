# Monitoring and Incident Response

## Uptime monitor

The `Production uptime monitor` workflow runs every five minutes. Configure
the repository variable `PRODUCTION_HEALTH_URL` with the exact HTTPS URL:

```text
https://your-app.azurewebsites.net/public/health.php
```

The check fails when the endpoint is unreachable, returns non-200, does not
confirm both PHP and database health, or takes longer than eight seconds.
On the first failure it creates one open issue labeled `uptime-alert`.
Subsequent checks avoid duplicate issues. When health returns, the workflow
adds a recovery timestamp and closes the incident automatically.

Repository watchers can enable GitHub notification email/push alerts for issue
activity. The monitor requires `issues: write`, but no production credentials.

## Structured application errors

Admin pages, public API endpoints, and the health route register the central
error boundary. It:

- disables browser error display
- attaches an `X-Request-ID` response header
- writes one-line JSON events to stderr/Azure App Service logs
- removes query strings from logged routes
- returns a generic error plus correlation ID instead of stack traces

Enable Azure App Service application logging and configure retention/export in
Azure Monitor. Search logs by the request ID reported by the user or health
response.

## Incident procedure

1. Open the active `uptime-alert` issue and note the first failure time.
2. Check Azure App Service availability, deployment history, and quota.
3. Search application logs near that time for `critical`, `error`, or the
   relevant request ID.
4. Check Azure MySQL availability, connections, storage, and firewall.
5. If a recent deployment caused the incident, use the documented rollback
   process or redeploy the last known-good commit.
6. Confirm `/public/health.php` returns 200 and database `reachable`.
7. Record root cause and corrective action in the incident before closing.

Never paste database credentials, encryption keys, raw SQL backups, recovery
tokens, or full customer records into a GitHub issue.
