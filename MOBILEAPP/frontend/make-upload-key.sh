#!/usr/bin/env bash
#
# Generates a fresh Android upload keystore and exports its certificate (PEM)
# for use with Google Play Console -> App signing -> Request upload key reset.
#
# Run from the frontend/ folder on a machine that has a JDK (keytool).
# If keytool is not on PATH, it usually ships with Android Studio at:
#   <Android Studio>/jbr/bin/keytool
#
# After generating: save the password + alias in a password manager, back up
# the .jks somewhere safe, and register the keystore in EAS:
#   eas credentials --platform android   (Keystore -> upload upload-keystore.jks)
#
set -euo pipefail

KEYSTORE="upload-keystore.jks"
ALIAS="upload"
CERT="upload_certificate.pem"
STOREPASS='kLrgcg/Y5czQTkeKUGl3srV5'

# Identity for the key — edit O/L/ST/C to your real org and 2-letter country.
DNAME="CN=AKM, OU=Mobile, O=AKM, L=City, ST=State, C=PH"

if ! command -v keytool >/dev/null 2>&1; then
  echo "ERROR: keytool not found on PATH." >&2
  echo "Install a JDK, or use Android Studio's: <Android Studio>/jbr/bin/keytool" >&2
  exit 1
fi

if [ -f "$KEYSTORE" ]; then
  echo "ERROR: $KEYSTORE already exists. Refusing to overwrite it." >&2
  echo "Move or delete it first if you really want a new key." >&2
  exit 1
fi

echo "==> Creating keystore: $KEYSTORE (alias: $ALIAS)"
keytool -genkeypair -v \
  -keystore "$KEYSTORE" \
  -alias "$ALIAS" \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storepass "$STOREPASS" \
  -keypass  "$STOREPASS" \
  -dname "$DNAME"

echo "==> Exporting certificate: $CERT"
keytool -export -rfc \
  -keystore "$KEYSTORE" \
  -alias "$ALIAS" \
  -file "$CERT" \
  -storepass "$STOREPASS"

echo
echo "==> Done."
echo "    Keystore : $KEYSTORE"
echo "    Alias    : $ALIAS"
echo "    Password : $STOREPASS   (SAVE THIS in a password manager)"
echo "    Cert     : $CERT   (upload this in Play Console upload-key reset)"
echo
echo "==> SHA-1 fingerprint of the new upload key:"
keytool -list -v -keystore "$KEYSTORE" -alias "$ALIAS" -storepass "$STOREPASS" \
  | grep -i 'SHA1:' || true
