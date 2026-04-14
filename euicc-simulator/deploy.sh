#!/bin/bash
# Deploy eUICC + IPA Simulators to Hetzner server
# Server: euicc.connectxiot.com

set -e

REMOTE_USER="root"
REMOTE_HOST="euicc.connectxiot.com"
REMOTE_DIR="/opt/euicc-simulator"
IPA_DIR="/opt/ipa-simulator"

echo "=== Deploying eUICC + IPA Simulators ==="

# 1. Create remote directories
ssh $REMOTE_USER@$REMOTE_HOST "mkdir -p $REMOTE_DIR $IPA_DIR"

# 2. Sync eUICC simulator
echo ">> Syncing eUICC Simulator..."
rsync -avz --exclude='__pycache__' --exclude='.git' --exclude='certs/*' \
    ./ $REMOTE_USER@$REMOTE_HOST:$REMOTE_DIR/

# 3. Sync IPA simulator
echo ">> Syncing IPA Simulator..."
rsync -avz --exclude='__pycache__' --exclude='.git' \
    ../ipa-simulator/ $REMOTE_USER@$REMOTE_HOST:$IPA_DIR/

# 4. Install dependencies and start services on remote
ssh $REMOTE_USER@$REMOTE_HOST << 'ENDSSH'
set -e

# Install Python 3.12 if not present
if ! command -v python3.12 &> /dev/null; then
    apt-get update
    apt-get install -y python3.12 python3.12-venv python3-pip
fi

# eUICC Simulator
cd /opt/euicc-simulator
python3.12 -m venv venv 2>/dev/null || true
source venv/bin/activate
pip install -r requirements.txt

# IPA Simulator
cd /opt/ipa-simulator
python3.12 -m venv venv 2>/dev/null || true
source venv/bin/activate
pip install -r requirements.txt

# Create systemd services
cat > /etc/systemd/system/euicc-simulator.service << 'EOF'
[Unit]
Description=ConnectX eUICC Simulator
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=/opt/euicc-simulator
Environment="EUICC_CERTS_DIR=/opt/euicc-simulator/certs"
Environment="SMDP_ADDRESS=smdpplus.connectxiot.com"
Environment="EIM_FQDN=eim.connectxiot.com"
Environment="CREATE_TEST_DATA=true"
ExecStart=/opt/euicc-simulator/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8100 --workers 1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/ipa-simulator.service << 'EOF'
[Unit]
Description=ConnectX IPA Simulator
After=network.target euicc-simulator.service

[Service]
Type=simple
User=root
WorkingDirectory=/opt/ipa-simulator
Environment="EUICC_SIMULATOR_URL=http://127.0.0.1:8100"
Environment="EIM_URL=https://eim.connectxiot.com"
Environment="SMDP_URL=https://smdpplus.connectxiot.com"
ExecStart=/opt/ipa-simulator/venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8101 --workers 1
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

# Setup Nginx
cp /opt/euicc-simulator/deploy/nginx.conf /etc/nginx/sites-available/euicc.connectxiot.com
ln -sf /etc/nginx/sites-available/euicc.connectxiot.com /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx

# SSL certificate (if not already obtained)
if [ ! -f /etc/letsencrypt/live/euicc.connectxiot.com/fullchain.pem ]; then
    certbot --nginx -d euicc.connectxiot.com --non-interactive --agree-tos --email admin@connectxiot.com
fi

# Reload and start services
systemctl daemon-reload
systemctl enable euicc-simulator ipa-simulator
systemctl restart euicc-simulator ipa-simulator

echo "=== Deployment complete ==="
echo "eUICC Simulator: https://euicc.connectxiot.com/health/euicc"
echo "IPA Simulator:   https://euicc.connectxiot.com/health/ipa"
echo "API Docs:        https://euicc.connectxiot.com/euicc/docs"
echo "IPA Docs:        https://euicc.connectxiot.com/ipa/docs"
ENDSSH

echo "=== Done ==="
