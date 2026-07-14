# Deploying the Self-Report Demo Site to AWS App Runner

Steps 1–5 happen once; step 8 is the recurring update path.

## 0. Prerequisites

- AWS CLI v2 authenticated against the **Supertab** account (see
  [Multiple AWS accounts](#multiple-aws-accounts-named-profiles) below if
  your default profile points at another org), Docker Desktop running.
- A **sandbox merchant API key** for Supertab Connect.
- This directory checked out locally (branch `feat/self-report-demo-site`,
  or `main` once PR #23 merges).

```bash
export AWS_PROFILE=supertab           # if using a named profile — set BEFORE the next line
export AWS_ACCOUNT=$(aws sts get-caller-identity --query Account --output text)
export AWS_REGION=eu-central-1        # pick your region
export REPO=$AWS_ACCOUNT.dkr.ecr.$AWS_REGION.amazonaws.com/supertab-self-report-demo
```

Sanity check you're in the right account before creating anything:
`aws sts get-caller-identity`.

## 1. Create the ECR repository (once)

```bash
aws ecr create-repository \
  --repository-name supertab-self-report-demo \
  --region $AWS_REGION
```

## 2. Build and push the image

```bash
cd demo/self-report
aws ecr get-login-password --region $AWS_REGION | \
  docker login --username AWS --password-stdin $REPO
docker build --platform linux/amd64 -t ${REPO}:latest .
docker push ${REPO}:latest
```

`--platform linux/amd64` matters on Apple Silicon — App Runner runs x86_64.

## 3. Create the ECR access role (once)

App Runner needs an IAM role to pull from private ECR. If the account
doesn't already have `AppRunnerECRAccessRole`:

```bash
aws iam create-role --role-name AppRunnerECRAccessRole \
  --assume-role-policy-document '{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"build.apprunner.amazonaws.com"},"Action":"sts:AssumeRole"}]}'
aws iam attach-role-policy --role-name AppRunnerECRAccessRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSAppRunnerServicePolicyForECRAccess
```

(The policy JSON is deliberately one line: copy-pasting indented JSON from
rendered docs can smuggle in non-breaking spaces, which IAM rejects as
`MalformedPolicyDocument`.)

## 4. Create the App Runner service (once)

Write the source configuration to a file first (the heredoc expands
`$AWS_ACCOUNT`/`$REPO` for you, and `file://` input sidesteps shell-quoting
and copy-paste whitespace issues):

```bash
cat > /tmp/apprunner-source.json <<EOF
{
  "AuthenticationConfiguration": {"AccessRoleArn": "arn:aws:iam::${AWS_ACCOUNT}:role/AppRunnerECRAccessRole"},
  "AutoDeploymentsEnabled": true,
  "ImageRepository": {
    "ImageIdentifier": "${REPO}:latest",
    "ImageRepositoryType": "ECR",
    "ImageConfiguration": {
      "Port": "8080",
      "RuntimeEnvironmentVariables": {
        "SUPERTAB_MERCHANT_API_KEY": "placeholder",
        "SUPERTAB_ENFORCEMENT": "observe"
      }
    }
  }
}
EOF

aws apprunner create-service \
  --region $AWS_REGION \
  --service-name supertab-self-report-demo \
  --source-configuration file:///tmp/apprunner-source.json \
  --instance-configuration '{"Cpu":"0.25 vCPU","Memory":"0.5 GB"}' \
  --health-check-configuration '{"Protocol":"HTTP","Path":"/healthz"}'

rm /tmp/apprunner-source.json
```

The API key starts as `placeholder` on purpose: sandbox registration needs
the site's domain, and the domain (ServiceUrl) only exists once the service
does. The service runs fine meanwhile — `/healthz` short-circuits before the
config check and challenge verification uses the public platform JWKS; only
analytics delivery would 401-and-drop. After step 6 issues the real key,
swap it in (console → Configuration → Edit env vars, or
`aws apprunner update-service` with the same source-config file) — the
service rolls automatically.

`SUPERTAB_BASE_URL` and `SUPERTAB_ANALYTICS` default to sandbox / on — set
them only to override.

Console alternative: Services → Create → Container registry/ECR, image
`…/supertab-self-report-demo:latest`, auto-deploy **on**, port **8080**,
0.25 vCPU / 0.5 GB, health check **HTTP** `/healthz`, plus the env vars.

## 5. Wait for it to go live and grab the URL

```bash
aws apprunner list-services --region $AWS_REGION \
  --query "ServiceSummaryList[?ServiceName=='supertab-self-report-demo'].[Status,ServiceUrl]" \
  --output table
```

Wait for `RUNNING`, then smoke-test:

```bash
HOST=<the-ServiceUrl>
curl -s  https://$HOST/healthz                                  # → ok
curl -si https://$HOST/.well-known/supertab/status | head -5    # → 404 {"supertab":true}, no-store
curl -s  https://$HOST/ | grep "SDK version"                    # → v1.4.0-beta.9
```

## 6. Register the site in sandbox and set the real key (required)

Register `https://<ServiceUrl>` as a merchant website in the **sandbox**
environment — registration is what issues the merchant API key for the
site. The backend only mints status challenges with `aud` = a registered
origin — probes silently get the decoy otherwise. If the App Runner
service is ever recreated, the URL changes: re-register.

Then replace the `placeholder` API key on the service (console →
Configuration → Edit env vars, or `aws apprunner update-service`) and wait
for the rollout to finish.

## 7. Trigger the real end-to-end probe

Fire a live-health check for the registered site (the backend's
`self_report` check). Expected: `200` with `runtime: null`,
`sdkVersion: "v1.4.0-beta.9"`,
`component: {kind: "php-sdk", version: "v1.4.0-beta.9"}`,
`enforcement: "observe"`, `eventReporting: true`.

Note: the backend resolves only `ts-sdk` against a registry so far
(laterpay/supertab-connect#1094); `php-sdk` degrades to "show version, no
nudge" until its resolver lands. That's expected, not a failure.

## 8. Updating (each new SDK release)

```bash
cd demo/self-report
# bump the pin in composer.json, then:
composer update getsupertab/connect-sdk-php
aws ecr get-login-password --region $AWS_REGION | docker login --username AWS --password-stdin $REPO
docker build --platform linux/amd64 -t ${REPO}:latest . && docker push ${REPO}:latest
```

Auto-deployment picks up the push and redeploys (~1–2 min). Commit the pin
+ lockfile change back to the repo.

**Config experiments** (no rebuild): edit `SUPERTAB_ENFORCEMENT` /
`SUPERTAB_ANALYTICS` on the service (console → Configuration → Edit, or
`aws apprunner update-service`) — the next probe reflects the new values.

**Cost**: ~$7/mo idle (0.25 vCPU / 0.5 GB provisioned) + a few cents ECR
storage. Delete with `aws apprunner delete-service` when no longer needed.

## Multiple AWS accounts (named profiles)

Keep your other org's CLI setup untouched by adding a named profile:

```bash
# Static access keys:
aws configure --profile supertab

# Or IAM Identity Center / SSO (typical for org accounts):
aws configure sso --profile supertab
# later sessions: aws sso login --profile supertab
```

Then either pass `--profile supertab` per command, or activate it for the
shell session (what step 0 assumes):

```bash
export AWS_PROFILE=supertab
aws sts get-caller-identity   # verify the account ID before creating resources
```

Docker inherits the choice automatically: `aws ecr get-login-password`
issues the token from the active profile, and `docker login`/`push` just
use that token. The one ordering gotcha: export `AWS_PROFILE` **before**
deriving `AWS_ACCOUNT` in step 0, since the account ID gets baked into
`$REPO`.
