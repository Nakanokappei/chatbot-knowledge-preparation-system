#!/bin/bash
# KPS AWS start/stop helper
# Usage: ./kps.sh start | stop | status

PROFILE="kps-company"
REGION="ap-northeast-1"
CLUSTER="kps-dev-cluster"
RDS="kps-dev-postgres"

case "$1" in
  start)
    echo "Starting KPS..."
    aws rds start-db-instance --db-instance-identifier $RDS --region $REGION --profile $PROFILE > /dev/null
    aws ecs update-service --cluster $CLUSTER --service kps-dev-app    --desired-count 1 --region $REGION --profile $PROFILE > /dev/null
    aws ecs update-service --cluster $CLUSTER --service kps-dev-worker --desired-count 1 --region $REGION --profile $PROFILE > /dev/null
    echo "Started. RDS takes ~2 min to become available."
    echo "Site: https://demo02.poc-pxt.com"
    ;;

  stop)
    echo "Stopping KPS..."
    aws ecs update-service --cluster $CLUSTER --service kps-dev-app    --desired-count 0 --region $REGION --profile $PROFILE > /dev/null
    aws ecs update-service --cluster $CLUSTER --service kps-dev-worker --desired-count 0 --region $REGION --profile $PROFILE > /dev/null
    aws rds stop-db-instance --db-instance-identifier $RDS --region $REGION --profile $PROFILE > /dev/null
    echo "Stopped. ECS tasks will drain in ~30s, RDS stops in ~1 min."
    ;;

  status)
    echo "=== ECS ==="
    aws ecs describe-services --cluster $CLUSTER --services kps-dev-app kps-dev-worker \
      --region $REGION --profile $PROFILE \
      --query 'services[*].{service:serviceName,desired:desiredCount,running:runningCount}' \
      --output table
    echo "=== RDS ==="
    aws rds describe-db-instances --db-instance-identifier $RDS \
      --region $REGION --profile $PROFILE \
      --query 'DBInstances[*].{id:DBInstanceIdentifier,status:DBInstanceStatus}' \
      --output table
    ;;

  *)
    echo "Usage: $0 start | stop | status"
    exit 1
    ;;
esac
