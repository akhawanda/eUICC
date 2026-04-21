# Deploy Runbook — euicc.connectxiot.com

Target host: Hetzner `204.168.200.66` (same box as eIM and SM-DP+).

## One-time server prep

1. Point DNS: `euicc.connectxiot.com A 204.168.200.66` (confirmed live).
2. Install packages:

    ```bash
    apt update
    apt install -y nginx python3.12 python3.12-venv python3-pip \
                   php8.3 php8.3-fpm php8.3-sqlite3 php8.3-mbstring php8.3-xml \
                   php8.3-curl php8.3-intl php8.3-bcmath php8.3-gd php8.3-zip \
                   composer nodejs npm certbot python3-certbot-nginx
    ```

3. Clone both simulator repos:

    ```bash
    mkdir -p /opt
    git clone https://github.com/akhawanda/eUICC.git /tmp/eUICC
    mv /tmp/eUICC/euicc-simulator /opt/
    mv /tmp/eUICC/ipa-simulator /opt/

    for d in /opt/euicc-simulator /opt/ipa-simulator; do
        cd "$d"
        python3.12 -m venv venv
        ./venv/bin/pip install -r requirements.txt
        cp .env.example .env     # edit as needed
    done
    ```

4. Install systemd units:

    ```bash
    cp /tmp/eUICC/frontend/deploy/systemd/*.service /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now euicc-sim ipa-sim
    ```

5. Install Nginx vhost and issue TLS:

    ```bash
    cp /tmp/eUICC/frontend/deploy/nginx/euicc.connectxiot.com.conf /etc/nginx/sites-available/
    ln -s /etc/nginx/sites-available/euicc.connectxiot.com.conf /etc/nginx/sites-enabled/
    nginx -t && systemctl reload nginx
    certbot --nginx -d euicc.connectxiot.com
    ```

6. Deploy Laravel frontend (from your Windows dev machine):

    ```bash
    cd eUICC/frontend
    bash scripts/deploy.sh   # first run triggers bootstrap.sh on server
    ```

## Post-install

- **Laravel .env** — set `SIM_SEED_TOKEN` to a strong random value. Copy the
  same value into `/opt/euicc-simulator/.env` as `LARAVEL_SEED_TOKEN`.
- Set `LARAVEL_SEED_URL=https://euicc.connectxiot.com/api/seed` in the sim's .env.
- `systemctl restart euicc-sim` to force a reseed.
- Log in to `https://euicc.connectxiot.com/` with the admin credentials printed
  by `bootstrap.sh` and rotate the password.

## Routine deploys

```bash
bash scripts/deploy.sh   # tar + scp + migrate + asset rebuild + php-fpm reload
```

Simulator-only changes (Python code):

```bash
ssh root@204.168.200.66 'cd /opt/euicc-simulator && git pull && systemctl restart euicc-sim'
ssh root@204.168.200.66 'cd /opt/ipa-simulator   && git pull && systemctl restart ipa-sim'
```
