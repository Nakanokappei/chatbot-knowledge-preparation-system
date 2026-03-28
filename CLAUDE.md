# Chatbot Knowledge Preparation System (KPS)

## Project Links

| | URL |
|---|---|
| **Staging site** | https://demo02.poc-pxt.com/ |
| **GitHub repository** | https://github.com/Nakanokappei/chatbot-knowledge-preparation-system |

## AWS Environment Management

Use `kps.sh` in the project root to start, stop, and check the status of KPS AWS resources (RDS + ECS).

```bash
# Start (RDS + ECS services)
./kps.sh start

# Stop (ECS services first, then RDS)
./kps.sh stop

# Check current status
./kps.sh status
```

> **Note:** RDS auto-restarts after 7 days of being stopped (AWS limitation).
> A Lambda (`kps-weekly-stop`) runs every Monday at 08:00 JST to reset the timer automatically.
