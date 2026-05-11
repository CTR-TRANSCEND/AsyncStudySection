#!/bin/bash
set -e

ENV_FILE=${1:-.env}

echo "Validating environment configuration: $ENV_FILE"

# Check if environment file exists
if [ ! -f "$ENV_FILE" ]; then
  echo "ERROR: Environment file not found: $ENV_FILE"
  exit 1
fi

# Load environment variables
set -a
source "$ENV_FILE"
set +a

# Validate required variables
REQUIRED_VARS=(
  "APP_ENV"
  "APP_DEBUG"
  "APP_NAME"
  "DB_HOST"
  "DB_NAME"
  "DB_USER"
  "DB_PASS"
)

MISSING_VARS=()

for var in "${REQUIRED_VARS[@]}"; do
  if [ -z "${!var}" ]; then
    MISSING_VARS+=("$var")
  fi
done

if [ ${#MISSING_VARS[@]} -ne 0 ]; then
  echo "ERROR: Missing required environment variables:"
  printf '%s\n' "${MISSING_VARS[@]}"
  exit 1
fi

# Validate APP_ENV value
if [[ ! "$APP_ENV" =~ ^(development|staging|production)$ ]]; then
  echo "ERROR: Invalid APP_ENV value: $APP_ENV"
  echo "Must be one of: development, staging, production"
  exit 1
fi

# Validate production-specific settings
if [ "$APP_ENV" = "production" ]; then
  if [ "$APP_DEBUG" != "false" ]; then
    echo "WARNING: APP_DEBUG should be false in production"
  fi

  if [ "$SESSION_COOKIE_SECURE" != "true" ]; then
    echo "WARNING: SESSION_COOKIE_SECURE should be true in production"
  fi
fi

# Validate disk space (minimum 10GB free)
FREE_SPACE=$(df -BG . | tail -1 | awk '{print $4}' | sed 's/G//')
if [ "$FREE_SPACE" -lt 10 ]; then
  echo "WARNING: Low disk space: ${FREE_SPACE}GB free"
fi

echo "Environment validation passed"
exit 0
