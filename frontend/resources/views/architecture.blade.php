@extends('layouts.app', ['title' => 'Architecture', 'header' => 'Architecture & message flows'])

@section('content')
    <div class="space-y-6 max-w-6xl">
        <p class="text-sm text-slate-600 dark:text-slate-400">
            How the four parties (eIM, SM-DP+, IPA, eUICC) interact per GSMA
            SGP.22 v3.1 (consumer) and SGP.32 v1.2 (IoT). Hover a note for
            the interface label; blue = ES9+/ES10 (RSP), purple = ESipa (eIM),
            green = ES2+ (operator → SM-DP+).
        </p>

        {{-- ====================================================== --}}
        {{-- 1. Components & interfaces overview                    --}}
        {{-- ====================================================== --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                Components and interfaces
            </h2>
            <pre class="mermaid">
graph LR
    OP[Operator BSS]
    EIM[eIM Server<br/>eim.connectxiot.com<br/>eimserver.connectxiot.com]
    SMDP[SM-DP+ Server<br/>smdpplus.connectxiot.com]
    IPA[IPA Simulator<br/>euicc.connectxiot.com<br/>port 8101]
    EUICC[eUICC Simulator<br/>euicc.connectxiot.com<br/>port 8100]

    OP  -- ES2+ --> SMDP
    EIM -- ES2+ --> SMDP
    IPA -- ESipa --> EIM
    IPA -- ES9+ --> SMDP
    IPA -- ES10a / ES10b / ES10c --> EUICC

    classDef eim fill:#e9d5ff,stroke:#7c3aed,color:#1e1b4b
    classDef smdp fill:#dcfce7,stroke:#15803d,color:#0b3019
    classDef rsp fill:#dbeafe,stroke:#2563eb,color:#0f1f4a
    classDef euicc fill:#fef3c7,stroke:#b45309,color:#3b2a09
    classDef op fill:#f1f5f9,stroke:#64748b,color:#0f172a

    class EIM eim
    class SMDP smdp
    class IPA rsp
    class EUICC euicc
    class OP op
            </pre>

            <div class="mt-4 grid grid-cols-2 gap-3 text-xs md:grid-cols-5">
                <div class="rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
                    <b>ES2+</b><br>Operator ↔ SM-DP+<br>Order profile, confirm ICCID
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
                    <b>ES9+</b><br>IPA ↔ SM-DP+<br>8-step mutual auth + BPP delivery
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
                    <b>ES10a/b/c</b><br>IPA ↔ eUICC<br>APDU / HTTP — auth, profile mgmt
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
                    <b>ESipa</b><br>IPA ↔ eIM<br>Polling, package relay
                </div>
                <div class="rounded border border-slate-200 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-950">
                    <b>ESep</b><br>eIM ↔ eUICC (via IPA)<br>PSMO/eCO ops
                </div>
            </div>
        </section>

        {{-- ====================================================== --}}
        {{-- 2. Profile download sequence (SGP.22)                  --}}
        {{-- ====================================================== --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                Profile download — SGP.22 §3.1 (8-step mutual auth)
            </h2>
            <pre class="mermaid">
sequenceDiagram
    autonumber
    participant OP as Operator
    participant DP as SM-DP+
    participant IPA
    participant EU as eUICC

    OP->>DP: ES2+ DownloadOrder(EID, ICCID)
    OP->>DP: ES2+ ConfirmOrder(matchingId)
    Note over DP: profile queued

    rect rgb(219,234,254)
    IPA->>EU: ES10b GetEuiccChallenge
    EU-->>IPA: euiccChallenge
    IPA->>EU: ES10b GetEuiccInfo1
    EU-->>IPA: euiccInfo1 (SVN, CI list)
    IPA->>DP: ES9+ InitiateAuthentication(challenge, info1)
    DP-->>IPA: serverSigned1 + serverChallenge + cert chain
    IPA->>EU: ES10b AuthenticateServer(serverSigned1)
    EU-->>IPA: euiccSigned1 (verifies DP, signs euiccChallenge)
    IPA->>DP: ES9+ AuthenticateClient(euiccSigned1)
    DP-->>IPA: smdpSigned2 + matchingId + profileMetadata
    IPA->>EU: ES10b PrepareDownload(smdpSigned2)
    EU-->>IPA: euiccSigned2 (ECDH otPK, confirmation)
    IPA->>DP: ES9+ GetBoundProfilePackage(euiccSigned2)
    DP-->>IPA: BPP (encrypted with SCP03t session keys)
    IPA->>EU: ES10b LoadBoundProfilePackage(BPP)
    EU-->>IPA: ProfileInstallationResult
    IPA->>DP: ES9+ HandleNotification(result)
    end
            </pre>
        </section>

        {{-- ====================================================== --}}
        {{-- 3. ESipa poll / retrieve eIM package (SGP.32)          --}}
        {{-- ====================================================== --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                Retrieve eIM Package — SGP.32 §5.14 (ESipa poll + relay)
            </h2>
            <pre class="mermaid">
sequenceDiagram
    autonumber
    participant OP as Enterprise
    participant EIM
    participant IPA
    participant EU as eUICC
    participant DP as SM-DP+

    OP->>EIM: queue package for EID

    rect rgb(233,213,255)
    IPA->>EIM: ESipa.getEimPackage { eidValue }
    alt scan request
        EIM-->>IPA: ipaEuiccDataRequest
        IPA->>EU: ES10c GetProfilesInfo / GetEID / GetEimConfig / GetCerts
        EU-->>IPA: eUICC data
        IPA->>EIM: provideEimPackageResult(ipaEuiccDataResponse, BF50/BF52)
    else download trigger
        EIM-->>IPA: profileDownloadTriggerRequest(smdp, matchingId)
        Note over IPA,DP: run full ES9+/ES10b download (see diagram above)
        IPA->>EIM: provideEimPackageResult(profileDownloadTriggerResult, BF50/BF54)
    else PSMO / eCO
        EIM-->>IPA: euiccPackageRequest
        IPA->>EU: ES10b EuiccPackage (ESep relay)
        EU-->>IPA: euiccPackageResult
        IPA->>EIM: provideEimPackageResult(euiccPackageResult, BF50/BF51)
    else nothing queued
        EIM-->>IPA: eimPackageError=1 (noEimPackageAvailable)
    end
    end
            </pre>
        </section>

        {{-- ====================================================== --}}
        {{-- 4. Message-level ESipa poll (actual wire shapes)       --}}
        {{-- ====================================================== --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                ESipa poll — wire-level message detail
            </h2>
            <p class="mb-4 text-xs text-slate-500">
                Exact JSON field names, ASN.1 tags and DER hex for each hop of
                <code>Retrieve eIM Package</code>. Tag notation uses GSMA
                SGP.32 convention (BFxx = context-specific long-form
                constructed). Lengths omitted for clarity.
            </p>
            <pre class="mermaid">
sequenceDiagram
    autonumber
    participant LAR as Laravel Console
    participant IPA
    participant EIM
    participant EU as eUICC sim

    Note over LAR,IPA: HTTP/JSON (loopback)
    LAR->>IPA: POST /api/ipa/devices<br/>{eid, eimId, eimFqdn, pollInterval}
    LAR->>IPA: POST /api/ipa/esipa/{eid}/poll-once

    Note over IPA,EIM: HTTPS / JSON
    IPA->>EIM: POST /gsma/rsp2/esipa/getEimPackage<br/>{ "eidValue": "263B5184…F3CA8" }

    alt queue has a scan request (what we hit today)
        EIM-->>IPA: 200 { "ipaEuiccDataRequest": {...} }
        IPA->>EU: GET /api/es10/{eid}/eid           ←  5A &lt;eid&gt;
        IPA->>EU: GET /api/es10/{eid}/profiles      ←  A0 profileInfoListOk
        IPA->>EU: GET /api/es10/{eid}/eim-config    ←  80 eimId  81 eimFqdn
        IPA->>EU: GET /api/es10/{eid}/certs         ←  EUM + eUICC X.509

        Note right of IPA: build DER:<br/>BF50 ─ [80] EXPLICIT<br/>  BF52 ─ [82] ipaEuiccDataResponse<br/>    80 eid<br/>    A2 eimConfigurationDataList<br/>    83 eumCertificate<br/>    84 euiccCertificate
        IPA->>EIM: POST /gsma/rsp2/esipa/provideEimPackageResult<br/>{ "provideEimPackageResult": "BF508204C3BF528204BE…" }
        EIM-->>IPA: 200 { "header": { "functionExecutionStatus": { "status": "Executed-Success" } } }

    else queue has a profile download trigger
        EIM-->>IPA: 200 { "profileDownloadTriggerRequest": { "smdpAddress", "matchingId", "eimTransactionId" } }
        Note over IPA,EU: runs full SGP.22 ES9+/ES10b dance
        IPA->>EIM: provideEimPackageResult<br/>BF50 > BF54 profileDownloadTriggerResult<br/>  {eimTransactionId, errorCode}

    else queue has a PSMO / eCO
        EIM-->>IPA: 200 { "euiccPackageRequest": {...} }
        IPA->>EU: ES10b EuiccPackage (ESep relay over APDU)
        EU-->>IPA: euiccPackageResult (signed)
        IPA->>EIM: provideEimPackageResult<br/>BF50 > BF51 euiccPackageResult

    else queue empty
        EIM-->>IPA: 200 { "eimPackageError": 1 }   ← noEimPackageAvailable
        Note right of IPA: IPA returns {status: no_package}<br/>to Laravel — no followup
    end
            </pre>

            <div class="mt-4 grid gap-3 md:grid-cols-2">
                <div class="rounded border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-800 dark:bg-amber-950">
                    <b class="block text-amber-700 dark:text-amber-300">ASN.1 pitfalls we hit</b>
                    <ul class="mt-2 list-disc pl-4 space-y-1">
                        <li>eIM expects JSON field <code>eidValue</code>, not <code>eid</code>.</li>
                        <li>eIM expects JSON field <code>provideEimPackageResult</code>, not <code>provideEimPackageResultData</code>.</li>
                        <li><code>ProvideEimPackageResult</code> is <code>[80] EXPLICIT CHOICE</code> — outer bytes must be <code>BF 50</code>.</li>
                        <li>Inner alternatives are <code>[81]</code>–<code>[84]</code> (→ <code>BF51</code>–<code>BF54</code>), not <code>[0]</code>–<code>[3]</code> (→ <code>A0</code>–<code>A3</code>).</li>
                    </ul>
                </div>

                <div class="rounded border border-sky-200 bg-sky-50 p-3 text-xs dark:border-sky-800 dark:bg-sky-950">
                    <b class="block text-sky-700 dark:text-sky-300">If the eIM returns <code>{eimPackageError: 1}</code></b>
                    <ul class="mt-2 list-disc pl-4 space-y-1">
                        <li>The EID matched a device in eIM DB (or we'd see <code>error: 2</code>).</li>
                        <li>But <code>eim_package_queue</code> has no <code>queued</code>/<code>in_progress</code> rows for that device_id — verify on the eIM dashboard.</li>
                        <li>If the queue has rows for a different enterprise/tenant than the device's association, eIM will not match them.</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- ====================================================== --}}
        {{-- 5. Where each piece lives                              --}}
        {{-- ====================================================== --}}
        <section class="rounded-lg border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-900">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">
                Infrastructure
            </h2>
            <pre class="mermaid">
graph TB
    subgraph "Hetzner 204.168.200.66"
        LAR[Laravel dashboard<br/>/var/www/euicc-frontend<br/>PHP-FPM 8.4]
        ES[euicc-sim.service<br/>:8100]
        IS[ipa-sim.service<br/>:8101]
        EIMGO[eim-go.service<br/>:8443]
        SMDPS[smdp-plus Laravel+Go]
    end

    BR[Browser]
    NG[Nginx]

    BR -- HTTPS --> NG
    NG -- / --> LAR
    NG -- /api/es10/ --> ES
    NG -- /api/ipa/ --> IS

    LAR -- "POST /api/management/euicc (push device)" --> ES
    LAR -- "POST /api/ipa/devices (register)" --> IS
    LAR -- "POST /api/ipa/esipa/.../poll-once" --> IS
    IS -- "ES10 (HTTP/APDU)" --> ES
    IS -- "ESipa (HTTPS)" --> EIMGO
    IS -- "ES9+ (HTTPS)" --> SMDPS
    ES -. "on boot: GET /api/seed (bearer token)" .-> LAR

    classDef laravel fill:#fef3c7,stroke:#b45309
    classDef py fill:#dbeafe,stroke:#2563eb
    classDef go fill:#e9d5ff,stroke:#7c3aed

    class LAR,SMDPS laravel
    class ES,IS py
    class EIMGO go
            </pre>
        </section>
    </div>

    <script type="module">
        import mermaid from 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs';
        const isDark = document.documentElement.classList.contains('dark') ||
                       window.matchMedia('(prefers-color-scheme: dark)').matches;
        mermaid.initialize({
            startOnLoad: true,
            theme: isDark ? 'dark' : 'neutral',
            sequence: { actorMargin: 60, messageAlign: 'center' },
            flowchart: { curve: 'basis' }
        });
    </script>
@endsection
