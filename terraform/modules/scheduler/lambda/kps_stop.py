"""
kps_stop.py — Lambda handler invoked by EventBridge Scheduler every
weekday at 19:30 JST to stop the KPS ECS services and RDS instance.

Stop order (reverse of start):
  1. ECS UpdateService desiredCount=0 for each service  (drain first)
  2. RDS StopDBInstance

Stopping ECS before RDS ensures in-flight requests are not dropped
mid-database-call.  Both calls are fire-and-forget; RDS will reach
'stopped' state within a few minutes after the call returns.
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
    """Stop KPS: ECS services first, then RDS instance."""
    rds = boto3.client("rds", region_name=REGION)
    ecs = boto3.client("ecs", region_name=REGION)
    results = {}

    # Set each ECS service desired-count to 0 before stopping RDS
    results["ecs"] = {}
    for service in SERVICES:
        try:
            ecs.update_service(cluster=CLUSTER, service=service, desiredCount=0)
            results["ecs"][service] = "stopped"
            logger.info("ECS %s/%s: desiredCount=0", CLUSTER, service)
        except ClientError as exc:
            code = exc.response["Error"]["Code"]
            results["ecs"][service] = code
            logger.error("ECS %s/%s: %s", CLUSTER, service, code)

    # Request RDS stop — safe to call when already stopped
    try:
        rds.stop_db_instance(DBInstanceIdentifier=RDS_ID)
        results["rds"] = "stopping"
        logger.info("RDS %s: stop requested", RDS_ID)
    except ClientError as exc:
        code = exc.response["Error"]["Code"]
        results["rds"] = code
        logger.warning("RDS %s: %s (skipped)", RDS_ID, code)

    return results
