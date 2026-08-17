#!/bin/bash
# One-time setup: run the DB migration and generate the RSA signing keypair.
# Run this once after configuring config/config.php with real DB credentials.

set -e
cd "$(dirname "$0")"

echo "Running database migration..."
php db/migrate.php

echo ""
echo "Validating LICENSE_PRIVATE_KEY..."
php -r "require 'includes/RsaSigner.php'; RsaSigner::sign(['setup_check' => true]); echo \"Private signing key is valid.\\n\";"

echo ""
echo "Setup complete."
echo "IMPORTANT: LICENSE_PRIVATE_KEY must remain in the server environment only."
echo "The desktop app embeds keys/license_signing_public.pem."
