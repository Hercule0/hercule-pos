# Final integration auth checks

- Live PHP sessions re-read the administrator account on protected requests.
- Disabled or deleted administrators are rejected immediately, not only after inactivity timeout.
- Role and forced-password-change state refresh from the database for live sessions.
- `Auth::changePassword()` uses the centralized `PasswordPolicy` and revokes remembered-login rows transactionally.
- These checks are integration-only until the combined branch passes the full CI suite.
