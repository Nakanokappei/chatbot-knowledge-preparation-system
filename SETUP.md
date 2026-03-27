# KPS First-Run Setup Guide

## 1. Prerequisites

- Docker and Docker Compose installed
- AWS account with Bedrock access enabled
- A PostgreSQL database (RDS or local)
- SMTP credentials for outbound mail (or use SES)

## 2. ALB IP Restriction

Before going live, restrict the ALB security group to your development IP on port 443.
This prevents public access during initial configuration.

```bash
# Replace SG_ID with your ALB security group ID and YOUR_IP with your public IP
aws ec2 authorize-security-group-ingress \
  --group-id <SG_ID> \
  --protocol tcp \
  --port 443 \
  --cidr <YOUR_IP>/32

# Remove the broad 0.0.0.0/0 rule if it exists
aws ec2 revoke-security-group-ingress \
  --group-id <SG_ID> \
  --protocol tcp \
  --port 443 \
  --cidr 0.0.0.0/0
```

Before production launch, the ALB security group should only allow port 443 from known IPs.
Open it to 0.0.0.0/0 only after completing setup and verifying the application is secure.

## 3. Configure SETUP_PASSPHRASE

In the application `.env` file, set a strong secret passphrase:

```
SETUP_PASSPHRASE=your-secret-passphrase-here
```

This enables setup mode only when **no users exist** in the database.

## 4. First-Run: Create System Administrator

1. Access the application URL in a browser.
   - If no users exist and `SETUP_PASSPHRASE` is set, you will be redirected to `/setup`.
2. Enter the email address for the system administrator account.
3. Enter the `SETUP_PASSPHRASE` you configured.
4. Click **Generate Invitation Link**.
5. Use the **Open Email Client** button to send the registration link,
   or copy the URL manually and send it to the system admin email address.

## 5. System Admin Registration

The system admin must click the invitation link, complete registration, and
**set a strong password immediately** after account creation.

The system admin account has no workspace binding and can manage all workspaces,
users, and system-level model templates from the admin dashboard (`/admin`).

## 6. After Setup: Disable Setup Mode

Once the system admin account is created, disable setup mode to prevent
unauthorized access to the setup endpoint:

```
SETUP_PASSPHRASE=
```

Set the value to empty in `.env` and restart the application.
The `/setup` route will redirect to `/login` when setup mode is inactive.
