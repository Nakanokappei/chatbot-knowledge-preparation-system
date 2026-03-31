"""
kps_start.py — Lambda handler invoked by EventBridge Scheduler every
weekday at 08:30 JST to start the KPS RDS instance and ECS services.

Start order:
  1. RDS StartDBInstance  (async — app will retry DB connection on boot)
  2. ECS UpdateService desiredCount=1 for each service

Both calls are intentionally fire-and-forget; the ECS health-check
ensures containers reach a healthy state before receiving traffic.
"""

import boto3
import logging
import os

from botocore.exceptions import ClientError

logger = logging.getLogger()
logger.setLevel(logging.INFO)

REGION   = os.environ["REGION"]
CLUSTER  = os.environ["CLUSTER"]
RDS_ID   = os.environ["RDS_ID"]
SERVICES = os.environ["SERVICES"].split(",")


def handler(event, context):
    """Start KPS: RDS instance, then ECS services."""
    rds = boto3.client("rds", region_name=REGION)
    ecs = boto3.client("ecs", region_name=REGION)
    results = {}

    # Request RDS start — safe to call when already available or starting
    try:
        rds.start_db_instance(DBInstanceIdentifier=RDS_ID)
        results["rds"] = "starting"
        logger.info("RDS %s: start requested", RDS_ID)
    except ClientError as exc:
        code = exc.response["Error"]["Code"]
        results["rds"] = code
        logger.warning("RDS %s: %s (skipped)", RDS_ID, code)

    # Set each ECS service desired-count to 1
    results["ecs"] = {}
    for service in SERVICES:
        try:
            ecs.update_service(cluster=CLUSTER, service=service, desiredCount=1)
            results["ecs"][service] = "started"
            logger.info("ECS %s/%s: desiredCount=1", CLUSTER, service)
        except ClientError as exc:
            code = exc.response["Error"]["Code"]
            results["ecs"][service] = code
            logger.error("ECS %s/%s: %s", CLUSTER, service, code)

    return results
