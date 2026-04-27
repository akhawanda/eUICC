#!/usr/bin/env bash
# Phase 2 E2E: 2× download, eIM swap, queued enable.
# Runs on the server (root@204.168.200.66) where mysql + php artisan + curl
# localhost are all available. Captures state at each step into a JSON
# report; the dev-side wrapper turns that into a Word doc.

set -euo pipefail

EID="${EID:-263B5184245F97B1F42F31AFA04F3CA8}"
SMDPP_FQDN="smdpplus.connectxiot.com"
EUICC_API="http://127.0.0.1:8100"
IPA_API="http://127.0.0.1:8101"
SMDPP_DIR="/var/www/smdpplus"
EIM_DIR="/var/www/eim"
REPORT="/tmp/phase2_report.json"

# ---- helpers -------------------------------------------------------------

step_marker=0
report_steps=()

emit_step() {
    # Append a step record (json string) to the report file.
    local payload="$1"
    if [[ ! -s "$REPORT" ]]; then echo '{"steps":[]}' > "$REPORT"; fi
    python3 -c "
import json,sys
with open('$REPORT') as f: r=json.load(f)
r['steps'].append(json.loads(sys.stdin.read()))
with open('$REPORT','w') as f: json.dump(r,f,indent=2)
" <<<"$payload"
}

list_profiles_summary() {
    curl -s "$EUICC_API/api/es10/$EID/profiles"
}

list_eims_summary() {
    curl -s "$EUICC_API/api/es10/$EID/eim-config"
}

allocate_matching_id() {
    # Reset SM-DP+ profile $1 to available, mint matching_id, return it.
    local profile_id="$1"
    cd "$SMDPP_DIR"
    php artisan tinker --execute="
\$p = App\Models\Profile::find($profile_id);
\$p->state = \"available\"; \$p->eid = null; \$p->save();
App\Models\DownloadOrder::where(\"profile_id\", $profile_id)->update([\"status\" => \"cancelled\"]);
\$vault = App\Services\Vault::getInstance();
\$mid = strtoupper(bin2hex(random_bytes(2))).\"-\".strtoupper(bin2hex(random_bytes(2))).\"-\".strtoupper(bin2hex(random_bytes(2))).\"-\".strtoupper(bin2hex(random_bytes(2)));
\$p->matching_id = \$mid; \$p->matching_id_hmac = \$vault->hmac(\$mid);
\$p->state = \"released\"; \$p->released_at = now(); \$p->save();
\$o = new App\Models\DownloadOrder();
\$o->enterprise_id = \$p->enterprise_id; \$o->profile_id = \$p->id;
\$o->iccid = \$p->iccid; \$o->iccid_hmac = \$p->iccid_hmac;
\$o->matching_id = \$mid; \$o->matching_id_hmac = \$vault->hmac(\$mid);
\$o->status = \"released\"; \$o->released_at = now(); \$o->save();
echo \$mid;
" 2>/dev/null | tail -1
}

queue_eim_action() {
    # Insert an eim_package_queue row with attached operation.
    # $1: type ("eco" | "psmo")
    # $2: action (e.g. "addEim", "deleteEim", "enable")
    # $3: JSON parameters string (lives on the eim_operations row — used by
    #     the Go server to encode action-specific params like eim cert/key
    #     paths for addEim, eimId for deleteEim)
    # $4: source_eim_registry_id (informational metadata)
    # $5: optional iccid (hex). When set, also written into the queue row's
    #     runtime_params (where the Go server reads the per-instance iccid
    #     for PSMO enable/disable/delete).
    local type="$1" action="$2" parameters_json="$3" source_eim="$4" iccid="${5:-}"
    cd "$EIM_DIR"
    php artisan tinker --execute="
\$ed = App\Models\EuiccDevice::where(\"eid\", \"$EID\")->first();
\$cfg = App\Models\EimConfiguration::where(\"euicc_device_id\", \$ed->id)->first();
\$params = json_decode('$parameters_json', true);
\$pkg = App\Models\EimPackage::create([
    \"eim_configuration_id\" => \$cfg ? \$cfg->id : null,
    \"euicc_device_id\" => \$ed->id,
    \"package_id\" => \"PHASE2-\".strtoupper(uniqid()),
    \"status\" => \"pending\",
    \"requested_at\" => now(),
    \"metadata\" => [\"source\" => \"phase2_e2e\", \"source_eim_registry_id\" => $source_eim],
    \"enterprise_id\" => \$ed->enterprise_id,
]);
\$op = App\Models\EimOperation::create([
    \"type\" => \"$type\",
    \"action\" => \"$action\",
    \"parameters\" => \$params,
    \"description\" => \"Phase2 $action\",
]);
\$pkg->operations()->attach(\$op->id, [\"sort_order\" => 0]);
\$rt = null;
\$iccidVal = \"$iccid\";
if (\$iccidVal !== \"\") {
    \$rt = [\"iccid\" => \$iccidVal, \"operations\" => [\$op->id => [\"iccid\" => \$iccidVal]]];
}
\$qi = App\Models\EimPackageQueue::create([
    \"euicc_device_id\" => \$ed->id,
    \"eim_package_id\" => \$pkg->id,
    \"runtime_params\" => \$rt,
    \"priority\" => 10,
    \"status\" => \"queued\",
    \"queued_at\" => now(),
    \"enterprise_id\" => \$ed->enterprise_id,
]);
echo \"queue_id=\".\$qi->id.\" pkg=\".\$pkg->package_id;
" 2>/dev/null | tail -1
}

register_device() {
    # IPA needs the device registered before poll-once or download will work.
    # eimId + eimFqdn come from the eUICC sim's eim-config (first associated eIM).
    # eimFqdn must be passed explicitly — if omitted, the IPA falls back to its
    # default which is the Laravel frontend host, not the Go eIM server.
    local pair eim_id eim_fqdn
    pair=$(curl -s "$EUICC_API/api/es10/$EID/eim-config" | python3 -c "
import sys, json
DEFAULT = 'eimserver.connectxiot.com'
d = json.load(sys.stdin).get('eimConfigurationDataList', [])
if d:
    e = d[0]
    print(e.get('eimId') or DEFAULT, e.get('eimFqdn') or DEFAULT)
else:
    print(DEFAULT, DEFAULT)
")
    eim_id=$(echo "$pair" | awk '{print $1}')
    eim_fqdn=$(echo "$pair" | awk '{print $2}')
    curl -s -X POST "$IPA_API/api/ipa/devices" \
        -H "Content-Type: application/json" \
        -d "{\"eid\":\"$EID\",\"euiccSimulatorUrl\":\"$EUICC_API\",\"eimId\":\"$eim_id\",\"eimFqdn\":\"$eim_fqdn\"}" > /dev/null
}

trigger_retrieve_eim_package() {
    register_device
    curl -s -X POST "$IPA_API/api/ipa/esipa/$EID/poll-once" -H "Content-Type: application/json" -d '{}'
}

# ---- prep ----------------------------------------------------------------

echo '{"steps":[],"eid":"'$EID'","started":"'$(date -Iseconds)'"}' > "$REPORT"

echo "(resetting eUICC sim for clean baseline ...)"
# Stop sim, wipe THIS EID's persisted rows, then start. Order matters: a
# `systemctl restart` lets the about-to-die process flush its (stale)
# in-memory state back to SQLite on shutdown, defeating any earlier wipe.
# By stopping first we make sure the next startup reseeds this EID from
# Laravel (which carries the canonical eim association).
systemctl stop euicc-sim
sleep 1
sqlite3 /opt/euicc-simulator/euicc_simulator.db "DELETE FROM eim_associations WHERE euicc_eid=\"$EID\"; DELETE FROM profiles WHERE euicc_eid=\"$EID\"; DELETE FROM euiccs WHERE eid=\"$EID\";"
systemctl start euicc-sim
for i in $(seq 1 20); do
    if curl -sf "$EUICC_API/api/es10/$EID/eid" > /dev/null 2>&1; then break; fi
    sleep 0.5
done

# Wipe any leftover profiles (reseed should already do this, but be safe)
for iccid in $(curl -s "$EUICC_API/api/es10/$EID/profiles" | python3 -c "import sys,json;[print(p['iccid']) for p in json.load(sys.stdin).get('profileInfoListOk',[])]"); do
    curl -s -X POST "$EUICC_API/api/es10/$EID/profiles/delete" -H "Content-Type: application/json" -d "{\"iccid\":\"$iccid\"}" > /dev/null
done

# ---- step 1: download profile A -----------------------------------------

echo "=== step 1: DOWNLOAD profile A ==="
MID_A=$(allocate_matching_id 100)
RESP_A=$(curl -s -X POST "$IPA_API/api/ipa/download/start" -H "Content-Type: application/json" -d "{\"eid\":\"$EID\",\"smdpAddress\":\"$SMDPP_FQDN\",\"matchingId\":\"$MID_A\"}")
PROFILES_AFTER_1=$(list_profiles_summary)
emit_step "$(jq -n --arg name "Step 1: Download Profile A" --arg mid "$MID_A" --argjson resp "$(echo "$RESP_A" | jq -c '{state, transactionId, error, steps: [.steps[].step + ": " + .steps[].message]}')" --argjson profiles "$PROFILES_AFTER_1" '{name: $name, matchingId: $mid, downloadResult: $resp, profilesAfter: $profiles}')"

# ---- step 2: download profile B -----------------------------------------

echo "=== step 2: DOWNLOAD profile B ==="
MID_B=$(allocate_matching_id 101)
RESP_B=$(curl -s -X POST "$IPA_API/api/ipa/download/start" -H "Content-Type: application/json" -d "{\"eid\":\"$EID\",\"smdpAddress\":\"$SMDPP_FQDN\",\"matchingId\":\"$MID_B\"}")
PROFILES_AFTER_2=$(list_profiles_summary)
emit_step "$(jq -n --arg name "Step 2: Download Profile B" --arg mid "$MID_B" --argjson resp "$(echo "$RESP_B" | jq -c '{state, transactionId, error, steps: [.steps[].step + ": " + .steps[].message]}')" --argjson profiles "$PROFILES_AFTER_2" '{name: $name, matchingId: $mid, downloadResult: $resp, profilesAfter: $profiles}')"

# Pluck iccid_a and iccid_b for later steps (1st download = iccid_a, 2nd = iccid_b)
ICCID_A=$(echo "$PROFILES_AFTER_2" | python3 -c "import sys,json;ps=json.load(sys.stdin)['profileInfoListOk'];print(ps[0]['iccid'])")
ICCID_B=$(echo "$PROFILES_AFTER_2" | python3 -c "import sys,json;ps=json.load(sys.stdin)['profileInfoListOk'];print(ps[1]['iccid'])")

# ---- step 3: list profiles ----------------------------------------------

echo "=== step 3: LIST PROFILES ==="
emit_step "$(jq -n --arg name "Step 3: List Profiles (verify 2 exist)" --arg iccidA "$ICCID_A" --arg iccidB "$ICCID_B" --argjson profiles "$PROFILES_AFTER_2" '{name: $name, iccidA: $iccidA, iccidB: $iccidB, profilesCount: ($profiles.profileInfoListOk|length), profiles: $profiles}')"

# ---- baseline eIM-db associations to match Laravel seed -----------------
# Restore eim_device_associations for this device to match the post-restart
# Laravel seed (only connectxiot.com active). Without this, leftover Tahwol
# associations from prior runs cause eim-go to sign with a stale identity.
cd "$EIM_DIR"
php artisan tinker --execute='
$ed = App\Models\EuiccDevice::where("eid", "'$EID'")->first();
App\Models\EimDeviceAssociation::where("euicc_device_id", $ed->id)->delete();
// Find the connectxiot registry by decrypting+matching (cheap, only 2 entries)
$conn = App\Models\EimRegistry::all()->firstWhere("eim_id", "eimserver.connectxiot.com");
App\Models\EimDeviceAssociation::create([
    "euicc_device_id" => $ed->id,
    "eim_registry_id" => $conn->id,
    "eim_id" => $conn->eim_id,
    "eim_id_hmac" => $conn->eim_id_hmac,
    "status" => "active",
    "is_primary" => 1,
    "enterprise_id" => $ed->enterprise_id,
]);
' >/dev/null 2>&1

# ---- step 4: add eIM Tahwol ---------------------------------------------

echo "=== step 4: ADD eIM Tahwol ==="
TAHWOL_PARAMS=$(cd "$EIM_DIR" && php artisan tinker --execute="echo json_encode(App\Models\EimRegistry::find(2)->toAddEimParameters());" 2>/dev/null | tail -1)
QUEUE_RES_4=$(queue_eim_action eco addEim "$TAHWOL_PARAMS" 1)
TRIGGER_4=$(trigger_retrieve_eim_package)
EIMS_AFTER_4=$(list_eims_summary)
# Mirror addEim onto eim-db so eim-go has Tahwol as a known signing identity
cd "$EIM_DIR"
php artisan tinker --execute='
$ed = App\Models\EuiccDevice::where("eid", "'$EID'")->first();
$reg = App\Models\EimRegistry::all()->firstWhere("eim_id", "eimTahwol");
App\Models\EimDeviceAssociation::firstOrCreate(
    ["euicc_device_id" => $ed->id, "eim_registry_id" => $reg->id],
    ["eim_id" => $reg->eim_id, "eim_id_hmac" => $reg->eim_id_hmac, "status" => "active", "is_primary" => 0, "enterprise_id" => $ed->enterprise_id]
);' >/dev/null 2>&1
emit_step "$(jq -n --arg name "Step 4: Add eIM Tahwol" --arg q "$QUEUE_RES_4" --argjson params "$TAHWOL_PARAMS" --argjson eims "$EIMS_AFTER_4" '{name: $name, queue: $q, addEimParams: $params, eimsAfter: $eims}')"

# ---- step 5: list eIMs (expect 2) ---------------------------------------

echo "=== step 5: LIST eIMs ==="
emit_step "$(jq -n --arg name "Step 5: List eIMs (verify both exist)" --argjson eims "$EIMS_AFTER_4" '{name: $name, eimsCount: ($eims.eimConfigurationDataList|length), eims: $eims}')"

# ---- step 6: remove eIM connectxiot.com ---------------------------------

echo "=== step 6: REMOVE eIM connectxiot.com ==="
DEL_PARAMS=$(jq -nc '{eimId: "eimserver.connectxiot.com"}')
QUEUE_RES_6=$(queue_eim_action eco deleteEim "$DEL_PARAMS" 2)
TRIGGER_6=$(trigger_retrieve_eim_package)
EIMS_AFTER_6=$(list_eims_summary)
# Mirror deleteEim onto eim-db so eim-go signs subsequent ops as Tahwol
cd "$EIM_DIR"
php artisan tinker --execute='
$ed = App\Models\EuiccDevice::where("eid", "'$EID'")->first();
$conn = App\Models\EimRegistry::all()->firstWhere("eim_id", "eimserver.connectxiot.com");
App\Models\EimDeviceAssociation::where(["euicc_device_id" => $ed->id, "eim_registry_id" => $conn->id])->delete();
$th = App\Models\EimRegistry::all()->firstWhere("eim_id", "eimTahwol");
App\Models\EimDeviceAssociation::where(["euicc_device_id" => $ed->id, "eim_registry_id" => $th->id])->update(["is_primary" => 1]);
' >/dev/null 2>&1
emit_step "$(jq -n --arg name "Step 6: Remove eIM connectxiot.com (keep only Tahwol)" --arg q "$QUEUE_RES_6" --argjson eims "$EIMS_AFTER_6" '{name: $name, queue: $q, eimsAfter: $eims, eimsCount: ($eims.eimConfigurationDataList|length)}')"

# ---- step 7: queue enable for profile B from Tahwol --------------------

echo "=== step 7: QUEUE ENABLE profile B from Tahwol ==="
ENABLE_PARAMS=$(jq -nc --arg iccid "$ICCID_B" '{iccid: $iccid}')
QUEUE_RES_7=$(queue_eim_action psmo enable "$ENABLE_PARAMS" 2 "$ICCID_B")
TRIGGER_7=$(trigger_retrieve_eim_package)
PROFILES_AFTER_7=$(list_profiles_summary)
emit_step "$(jq -n --arg name "Step 7: Queue Enable for Profile B (via Tahwol)" --arg iccid "$ICCID_B" --arg q "$QUEUE_RES_7" --argjson profiles "$PROFILES_AFTER_7" '{name: $name, queue: $q, targetIccid: $iccid, profilesAfter: $profiles}')"

# ---- step 8: list profiles, validate enabled state ----------------------

echo "=== step 8: LIST PROFILES (validate enabled state) ==="
ENABLED_ICCID=$(echo "$PROFILES_AFTER_7" | python3 -c "import sys,json;ps=json.load(sys.stdin)['profileInfoListOk'];en=[p['iccid'] for p in ps if p.get('profileState')==1];print(en[0] if en else '')")
emit_step "$(jq -n --arg name "Step 8: List Profiles (validate enabled state)" --arg expected "$ICCID_B" --arg actual "$ENABLED_ICCID" --argjson profiles "$PROFILES_AFTER_7" '{name: $name, expectedEnabled: $expected, actualEnabled: $actual, profiles: $profiles, pass: ($expected == $actual)}')"

echo "=== DONE ==="
echo "Report: $REPORT"
