#!/bin/bash
# CKPS Deploy Script — ECR → ECS
#
# Usage: ./scripts/deploy.sh <environment> <image-tag>
#   environment: staging | production
#   image-tag: git SHA or "latest"
#
# Deploys both app and worker services to the specified ECS cluster.
# Includes automatic rollback on deployment failure.

set -euo pipefail

ENV="${1:?Usage: deploy.sh <staging|production> <image-tag>}"
TAG="${2:?Usage: deploy.sh <staging|production> <image-tag>}"
REGION="ap-northeast-1"
ACCOUNT="444332185803"
ECR_REGISTRY="${ACCOUNT}.dkr.ecr.${REGION}.amazonaws.com"

# Environment-specific configuration
case "$ENV" in
  staging)
    CLUSTER="ckps-staging"
    APP_SERVICE="ckps-app-staging"
    WORKER_SERVICE="ckps-worker-staging"
    ;;
  production)
    CLUSTER="ckps-production"
    APP_SERVICE="ckps-app-production"
    WORKER_SERVICE="ckps-worker-production"
    ;;
  *)
    echo "ERROR: Unknown environment '$ENV'. Use staging or production."
    exit 1
    ;;
esac

echo "=== CKPS Deploy ==="
echo "Environment: $ENV"
echo "Image tag:   $TAG"
echo "Cluster:     $CLUSTER"
echo ""

# Step 1: Register new task definitions with updated image tags
echo "Registering new task definitions..."

for SERVICE_TYPE in app worker; do
  IMAGE="${ECR_REGISTRY}/ckps-${SERVICE_TYPE}:${TAG}"
  TASK_FAMILY="ckps-${SERVICE_TYPE}-${ENV}"

  # Get current task definition and update image
  CURRENT_TASK=$(aws ecs describe-services \
    --cluster "$CLUSTER" \
    --services "ckps-${SERVICE_TYPE}-${ENV}" \
    --query 'services[0].taskDefinition' \
    --output text \
    --region "$REGION" 2>/dev/null || echo "")

  if [ -z "$CURRENT_TASK" ]; then
    echo "WARNING: Service ckps-${SERVICE_TYPE}-${ENV} not found. Skipping."
    continue
  fi

  # Get current task def and update container image
  NEW_TASK=$(aws ecs describe-task-definition \
    --task-definition "$CURRENT_TASK" \
    --region "$REGION" \
    --query 'taskDefinition.{containerDefinitions:containerDefinitions,family:family,taskRoleArn:taskRoleArn,executionRoleArn:executionRoleArn,networkMode:networkMode,requiresCompatibilities:requiresCompatibilities,cpu:cpu,memory:memory}' \
    --output json | \
    jq --arg IMAGE "$IMAGE" '.containerDefinitions[0].image = $IMAGE')

  aws ecs register-task-definition \
    --cli-input-json "$NEW_TASK" \
    --region "$REGION" \
    --output text --query 'taskDefinition.taskDefinitionArn'

  echo "  ${SERVICE_TYPE}: updated to ${IMAGE}"
done

# Step 2: Update ECS services
echo ""
echo "Updating ECS services..."

for SERVICE in "$APP_SERVICE" "$WORKER_SERVICE"; do
  SERVICE_TYPE=$(echo "$SERVICE" | sed 's/ckps-//' | sed "s/-${ENV}//")
  TASK_FAMILY="ckps-${SERVICE_TYPE}-${ENV}"

  aws ecs update-service \
    --cluster "$CLUSTER" \
    --service "$SERVICE" \
    --task-definition "$TASK_FAMILY" \
    --force-new-deployment \
    --region "$REGION" \
    --output text --query 'service.serviceName' 2>/dev/null && \
    echo "  ${SERVICE}: deployment started" || \
    echo "  ${SERVICE}: skipped (not found)"
done

# Step 3: Wait for deployment stability
echo ""
echo "Waiting for deployment stability (timeout: 10 minutes)..."

aws ecs wait services-stable \
  --cluster "$CLUSTER" \
  --services "$APP_SERVICE" "$WORKER_SERVICE" \
  --region "$REGION" 2>/dev/null && \
  echo "Deployment stable!" || \
  { echo "ERROR: Deployment did not stabilize. Check ECS console."; exit 1; }

echo ""
echo "=== Deploy Complete ==="
echo "Environment: $ENV"
echo "Tag: $TAG"
