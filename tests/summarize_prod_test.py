import json
import sys

with open(sys.argv[1] if len(sys.argv) > 1 else "/tmp/prod_test.json") as f:
    r = json.load(f)

print("state:", r.get("state"))
print("transactionId:", r.get("transactionId"))
print("error:", r.get("error"))
print()
print("=== steps ===")
for s in r.get("steps", []):
    print(f"  {s['step']}: {s['message']}")
print()
print("=== SM-DP+ responses ===")
for t in r.get("_trace", []):
    if t.get("actor_from") == "smdpplus" and t.get("direction") == "response":
        ep = t.get("endpoint")
        st = t.get("http_status")
        print(f"  {ep} -> HTTP {st}")
        body = t.get("http_body") or ""
        try:
            j = json.loads(body)
            hdr = j.get("header", {}).get("functionExecutionStatus", {})
            sc = hdr.get("statusCodeData", {})
            if hdr.get("status") == "Failed":
                print(f"    Failed: subjectCode={sc.get('subjectCode')} reasonCode={sc.get('reasonCode')}")
                print(f"    subjectIdentifier: {sc.get('subjectIdentifier')}")
                print(f"    message: {sc.get('message')}")
            elif "transactionId" in j:
                print(f"    Executed-Success txId={j.get('transactionId')[:16]}...")
        except Exception:
            print(f"    body: {body[:200]}")
