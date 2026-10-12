# SPDX-License-Identifier: AGPL-3.0-or-later
# Sourced by scripts/backup/restore-drill.sh and scripts/deploy/deploy-check.sh: the stand-in for the off-site bucket
# (scripts/backup/drill.compose.yaml's s3 service, SeaweedFS) behind a certificate authority that lives as long as the
# run, so pgBackRest and restic reach it over TLS as they reach a real one. The caller defines `dc` (its compose),
# `fail`, DRILL_DIR, and BACKUP_S3_KEY, BACKUP_S3_SECRET and BACKUP_S3_BUCKET.

# The authority, the bucket's certificate and its one identity, made with the backup image's own openssl: the one built
# here, or the one given (make staging-check builds nothing, and passes the published image by its digest).
stand_in_bucket_keys() {
  local image=${1:-}
  if [[ -z "$image" ]]; then dc build -q postgres; image=twes-in-postgres-backup; fi
  mkdir -p "$DRILL_DIR/certs"
  docker run --rm -u "$(id -u):$(id -g)" -v "$DRILL_DIR/certs:/c" -w /c --entrypoint sh "$image" -c '
    openssl req -x509 -newkey rsa:2048 -nodes -keyout ca.key -out ca.crt -days 1 -subj /CN=twes-drill-ca 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -keyout s3.key -out s3.csr -subj /CN=s3 2>/dev/null
    printf "subjectAltName=DNS:s3\n" > ext
    openssl x509 -req -in s3.csr -CA ca.crt -CAkey ca.key -CAcreateserial -out s3.crt -days 1 -extfile ext 2>/dev/null
    chmod 644 ./*'
  printf '{"identities":[{"name":"backup","credentials":[{"accessKey":"%s","secretKey":"%s"}],"actions":["Admin","Read","Write","List","Tagging"]}]}\n' \
    "$BACKUP_S3_KEY" "$BACKUP_S3_SECRET" > "$DRILL_DIR/s3.json"
}

# The bucket service started and the bucket made; SeaweedFS answers its shell only once its master has a leader.
stand_in_bucket_up() {
  dc up -d s3
  for _ in $(seq 1 60); do
    dc exec -T s3 sh -c "echo 's3.bucket.create -name $BACKUP_S3_BUCKET' | weed shell" 2>/dev/null | grep -q "created bucket" && break
    sleep 2
  done
  dc exec -T s3 sh -c "echo 's3.bucket.list' | weed shell" 2>/dev/null | grep -q "$BACKUP_S3_BUCKET" || fail "the stand-in bucket was never made"
}
