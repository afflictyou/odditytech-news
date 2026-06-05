# Canonical Tag Vocabulary

**Source of truth:** `config/canonical_tags.json`
**Issue:** [SIG-181](/SIG/issues/SIG-181) (B4 of the [SIG-174 plan](/SIG/issues/SIG-174#document-plan))
**Last revised:** 2026-05-30 — initial vocabulary

The SSCI pipeline accepts free-form tags on `POST /api/v1/headlines/`, but cluster detection and weekly digest filtering need a stable vocabulary. This document defines that vocabulary and lists every alias that resolves to it.

The runtime alias map is `config/canonical_tags.json`. **Edit that file, not the tables below** — the tables here are generated from the JSON to keep humans and machines reading the same map. After editing the JSON, regenerate this doc (or update by hand), regenerate `docs/migrations/0004_normalize_tags.sql` from the JSON (the migration file is mechanically generated; the in-repo regenerator script lives alongside SIG-181's verification scripts), and run the migration in phpMyAdmin against any environment whose `headlines` table you want re-canonicalised.

---

## How resolution works

1. The ingest tag is lowercased and slug-normalised (`Neuromorphic` → `neuromorphic`, `Brain Computer Interface` → `brain-computer-interface`).
2. The slug is looked up in `aliases` (case-sensitive on slug form).
3. If a mapping exists, the **canonical slug** is persisted. Otherwise the tag passes through unchanged so the long tail is preserved — it just doesn't participate in the canonical vocabulary.
4. Per-row deduplication runs after mapping so `["neuromorphic","memristor"]` becomes `["neuromorphic"]`.

This is alias resolution only — there is no embedding lookup, no fuzzy matching, no LLM call. Adding a new alias is one line in the JSON.

---

## Coverage

Snapshot of the 191-article corpus on 2026-05-30:

- **193 distinct tag slugs** in use
- **612 total tag-usages**
- **593 (96.9%) covered** by the 32 canonical tags below
- 19 usages remain unmapped tail: vendor names (`google`, `intel`, `sony`, `deepmind`), `archaeology`, `history`, `sports`, `china`, `dolphin`, `animal-communication`, `nlp`, `translation`. These are intentionally left unmapped — see "Why some tags are not canonical" below.

Re-running the verification (see SIG-181 thread for the script) against a new snapshot is how you decide whether to add or split a canonical.

---

## Canonical tags (32)

Each row gives the canonical slug, a short editorial description, and every alias slug that resolves to it. The canonical itself is also listed as an alias-to-self in the JSON so resolution is uniform.

| # | Canonical | Description | Aliases that resolve to it |
|---|-----------|-------------|---------------------------|
| 1 | `ai` | General AI / ML umbrella when no narrower canonical applies. | `ai`, `machine-learning`, `ml`, `neural-network`, `neural-networks`, `computing` |
| 2 | `llm` | Large language models. Use for model-family stories, KV-cache, decoding, etc. | `llm`, `llms`, `kv-cache` |
| 3 | `agents` | Autonomous / tool-using agent systems. | `agents`, `ai-agent` |
| 4 | `gpt` | The GPT model family specifically. Use `ai` or `llm` when not GPT-specific. | `gpt`, `openai` |
| 5 | `mechanistic-interpretability` | Circuits, neuron-level interpretability, debugging tools (e.g. Goodfire Silico). | `mechanistic-interpretability`, `interpretability`, `goodfire` |
| 6 | `ai-safety` | Alignment, deception, reward-hacking, chain-of-thought monitoring. | `ai-safety`, `alignment`, `safety`, `reward-hacking`, `chain-of-thought`, `anthropic` |
| 7 | `reasoning` | Inner monologue, planning, problem-solving traces. | `reasoning`, `inner-speech`, `planning`, `control-theory`, `multitasking`, `pattern-matching`, `cognition` |
| 8 | `ai-for-science` | Autonomous labs, scientific discovery loops, AI-authored experiments. | `ai-for-science`, `science`, `scientific-computing`, `scientific-discovery`, `scientific-ml`, `science-automation`, `autonomous-labs`, `research` |
| 9 | `ai-for-math` | AI cracking open math problems; algorithm discovery. | `ai-for-math`, `math`, `mathematics`, `applied-math`, `godel`, `goedel`, `algorithm-discovery`, `algorithms`, `mathematical-creativity`, `evolutionary-ai`, `openevolve`, `alphaevolve`, `compiler`, `programming-languages`, `optimization` |
| 10 | `generative-models` | Diffusion, generative AI, generative image/audio/video. | `generative-models`, `generative-ai`, `diffusion-model`, `diffusion-models`, `self-supervised-learning`, `graph-neural-network`, `lip-sync`, `uncanny-valley` |
| 11 | `ai-evaluation` | Benchmarks, evals, peer-review of AI claims. | `ai-evaluation`, `evaluation`, `benchmarks`, `peer-review` |
| 12 | `robotics` | Embodied robotics, swarm robotics. | `robotics`, `swarms`, `swarm-robotics`, `ai-robotics` |
| 13 | `neuromorphic` | Brain-inspired hardware: memristors, spiking chips, photonic neurons, AI-substrate hardware. | `neuromorphic`, `memristor`, `ai-hardware`, `hardware`, `bio-inspired`, `stdp`, `spiking-neural-network`, `photonic-computing` |
| 14 | `neuro-symbolic` | Hybrid neuro-symbolic AI architectures. Distinct from neuromorphic hardware. | `neuro-symbolic`, `neuro-symbolic-ai`, `hybrid`, `brain-inspired` |
| 15 | `biocomputing` | Living neurons, organoids, wetware, fungal/mycelium, biohybrid. | `biocomputing`, `bio-computing`, `organoids`, `neurons`, `mycelium`, `fungal-computing`, `biohybrid` |
| 16 | `molecular-computing` | Shape-shifting molecules, single-molecule logic, DNA computing devices. | `molecular-computing`, `dna-robots` |
| 17 | `synthetic-biology` | Genetic circuits, engineered biology. | `synthetic-biology`, `genetic-circuits` |
| 18 | `proteins` | Protein folding, design, AlphaFold-class work. | `proteins`, `alphafold`, `protein-design`, `isomorphic-labs` |
| 19 | `medical-ai` | Drug discovery, clinical AI, health, longevity, oncology, cell biology applied to medicine. | `medical-ai`, `drug-discovery`, `drug-delivery`, `biotech`, `insilico-medicine`, `clinical-trials`, `coma`, `longevity`, `aging`, `health`, `cancer`, `cell-biology`, `biology`, `neurology`, `biophysics` |
| 20 | `genomics` | Genomics, epigenetics, DNA data storage. | `genomics`, `dna`, `dna-storage`, `data-storage`, `epigenetics` |
| 21 | `bci` | Brain-computer interfaces, neuroprosthetics, neurostimulation, neural wearables. | `bci`, `brain-computer-interface`, `brain-machine-interface`, `neuroprosthetics`, `neurostimulation`, `brain-stimulation`, `tfus`, `ultrasound`, `wearable`, `lucid-dreaming` |
| 22 | `neuroscience` | Brains, neurons, cognition (non-medical, non-BCI). | `neuroscience`, `working-memory`, `tryptophan` |
| 23 | `consciousness` | Consciousness, panpsychism, IIT, philosophy of mind, NDE. | `consciousness`, `panpsychism`, `iit`, `philosophy-of-mind`, `near-death-experience`, `nde`, `philosophy`, `simulation-hypothesis` |
| 24 | `quantum-computing` | Qubits, error correction, NISQ, quantum advantage claims. | `quantum-computing`, `quantum`, `qubit`, `qubits`, `quantum-noise`, `nisq`, `circuit-depth`, `quantum-advantage`, `noise` |
| 25 | `quantum-biology` | Quantum effects in living systems. | `quantum-biology`, `photosynthesis` |
| 26 | `physics` | Physics topics that are not quantum-computing-specific. | `physics`, `plasma`, `dusty-plasma`, `optics`, `condensed-matter`, `particle-accelerator`, `altermagnetism`, `thermodynamics`, `brownian-motion`, `magnets`, `quantum-physics`, `chaos`, `chaos-prediction`, `ev`, `rare-earth`, `dielectric` |
| 27 | `physics-informed-ml` | PINNs, ML for simulation, ML-accelerated PDE solving, climate modeling. | `physics-informed-ml`, `physics-informed`, `pde`, `climate-modeling`, `climate` |
| 28 | `materials-science` | Novel materials, graphene, polymers, nanotechnology, dielectrics. | `materials-science`, `materials`, `polymers`, `graphene`, `nanotechnology`, `nanoengineering` |
| 29 | `chemistry` | Chemistry results not better placed under materials-science or proteins. | `chemistry` |
| 30 | `energy` | Energy efficiency, energy economics, AI power use, Jevons paradox. | `energy`, `energy-efficiency`, `jevons-paradox`, `extreme-computing`, `ai-economics`, `democratization` |
| 31 | `memory-systems` | Computing memory architectures: memory chips, memory-compute fusion, adaptive memory hierarchies. (KV-cache aliases to `llm`, not here.) | `memory-systems`, `memory`, `memory-chip`, `memory-compute`, `adaptive` |
| 32 | `space` | Space, astrobiology, SETI, exoplanets. | `space`, `seti`, `astrobiology`, `exoplanets`, `panspermia` |

---

## Two intentional editorial calls

**`neuromorphic` and `neuro-symbolic` are kept distinct.** The original SIG-167 thread floated them as collapse candidates ("`neuromorphic` / `neuro-symbolic` / `brain-inspired`"), but the SIG-167/SIG-169 clustering work treats them as different things: `neuromorphic` is brain-inspired *hardware* (memristors, spiking chips, photonic substrates — Cluster A), `neuro-symbolic` is a *software architecture* (Tufts hybrid VLA, Near-Miss 1). Collapsing them would erase a real distinction the editorial framework already relies on. `brain-inspired` resolves to `neuro-symbolic` because the only `brain-inspired` use seen so far has been the Tufts hybrid context.

**Vendor names mostly do not get canonical slugs.** `gpt` / `openai` survive as a canonical because the GPT family is editorially load-bearing (5+ articles span model-version stories). `anthropic` rolls into `ai-safety` because every Anthropic article in the corpus is interpretability/alignment work. `deepmind`, `google`, `intel`, `sony` stay unmapped — they are vendor mentions, not topics, and including them would inflate vendor-frequency without informing cluster detection.

---

## Why some tags are not canonical

The 19 unmapped tag-usages are deliberate:

| Tag | Why unmapped |
|-----|--------------|
| `sports`, `archaeology`, `history` | Out of SSCI editorial scope. Low frequency, no clustering signal. |
| `deepmind`, `google-deepmind`, `google`, `intel`, `sony` | Vendor names, not topics. See editorial call above. |
| `nlp`, `translation`, `animal-communication`, `dolphin`, `china` | One-off oddity tags. Promote to canonical if they cluster in a future window. |

A tag becoming "unmapped" is not a failure mode — it is a signal that either (a) the tag is a true tail entry that should pass through, or (b) the canonical vocabulary needs a new entry. Re-run the coverage script against a fresh snapshot quarterly (or after any large ingest burst) and re-evaluate.

---

## How to add a new canonical or alias

1. Edit `config/canonical_tags.json`:
   - To add a canonical, append to `canonical_tags` with `slug`/`display`/`description`.
   - To add an alias, add a key in `aliases` mapping the source slug to an existing canonical slug.
2. Update the table in this document so humans can read the same map.
3. Regenerate `docs/migrations/0004_normalize_tags.sql` from the JSON (the file is mechanically generated; the regenerator script lives in the SIG-181 workspace and is in the issue thread).
4. Open the regenerated `0004_normalize_tags.sql` in phpMyAdmin against any environment whose `headlines` table you want re-canonicalised. The migration is idempotent — re-running it after only adding an alias is safe.
5. (Optional) Hit `GET /api/v1/tags?include_aliases=1` to confirm the deployed runtime sees the new entry.

---

## API surface (added in this issue)

- `GET /api/v1/tags` — returns the canonical list as JSON.
  - `?include_aliases=1` — include the alias array under each canonical.
- `POST /api/v1/headlines/` — alias resolution runs on the `tags` field before insert. No client-side change required: existing posts that send `neuromorphic` will now persist as `neuromorphic` (canonical), and posts that send `memristor` will persist as `neuromorphic`.
