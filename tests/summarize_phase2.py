import json, sys

path = sys.argv[1] if len(sys.argv) > 1 else "/tmp/phase2_report.json"
with open(path) as f:
    r = json.load(f)

for s in r["steps"]:
    print("=", s["name"])
    for k, v in s.items():
        if k == "name":
            continue
        if k == "profilesAfter" and isinstance(v, dict):
            ps = v.get("profileInfoListOk", [])
            print(f"  profiles ({len(ps)}):")
            for p in ps:
                print(f"    iccid={p['iccid']} profileState={p['profileState']}")
        elif k == "profiles" and isinstance(v, dict):
            ps = v.get("profileInfoListOk", [])
            print(f"  profiles ({len(ps)}):")
            for p in ps:
                print(f"    iccid={p['iccid']} profileState={p['profileState']}")
        elif k == "eimsAfter" and isinstance(v, dict):
            es = v.get("eimConfigurationDataList", [])
            print(f"  eims ({len(es)}):")
            for e in es:
                print(f"    eimId={e.get('eimId')} fqdn={e.get('eimFqdn')}")
        elif k == "eims" and isinstance(v, dict):
            es = v.get("eimConfigurationDataList", [])
            print(f"  eims ({len(es)}):")
            for e in es:
                print(f"    eimId={e.get('eimId')} fqdn={e.get('eimFqdn')}")
        elif isinstance(v, dict):
            print(f"  {k}: keys={list(v.keys())}")
        elif isinstance(v, list):
            print(f"  {k}: len={len(v)}")
        else:
            print(f"  {k}: {str(v)[:140]}")
    print()
