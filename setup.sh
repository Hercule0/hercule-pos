#!/bin/bash
# One-time setup: run the DB migration and generate the RSA signing keypair.
# Run this once after configuring config/config.php with real DB credentials.

set -e
cd "$(dirname "$0")"

echo "Running database migration..."
php db/migrate.php

echo ""
echo "Generating RSA signing keypair..."
php -r "require 'includes/RsaSigner.php'; RsaSigner::generateKeypair(); echo \"Keypair written to keys/\n\";"

echo ""
echo "Setup complete."
echo "IMPORTANT: keys/license_signing_public.pem is what gets embedded in the"
echo "desktop app (Phase 5). keys/license_signing_private.pem must NEVER leave"
echo "this server."
