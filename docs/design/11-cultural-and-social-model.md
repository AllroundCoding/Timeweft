# 11 · Cultural & Social Model  🟢 built (culture, faith, Big Five)

How a culture is represented, where its values come from, and how individuals vary within
it. This is the variable set behind cooperation ([07](07-cooperation-projects-institutions.md)),
economy ([06](06-resources-economy-trade.md)), and generation ([08](08-direction-and-generation.md)).

## Culture as a vector

A culture is a small vector of dimensions (0–100), each modulating agent dispositions and
which institutions form. Synthesized from **Hofstede** + **Schwartz** and tied to systems we
already have:

| Dimension | Roots | Drives |
|---|---|---|
| **Collectivism** | Hofstede IDV⁻¹, Schwartz embeddedness | cohesion baseline, project participation (07) |
| **Hierarchy** | Hofstede PDI, Schwartz hierarchy↔egalitarian | institution type (council vs magistrate) |
| **Tradition** | Hofstede UAI, Inglehart traditional↔secular | resistance to change / the Boserup innovation ratchet (05/06) |
| **Long-term orientation** | Hofstede LTO | saving vs spending, planning horizon (06) |
| **Restraint** | Hofstede IVR⁻¹ | consumption discipline, asceticism |
| **Achievement** | Hofstede MAS, Schwartz mastery↔harmony | competition vs communal welfare |
| **Piety** | custom | how strongly faith tenets bind behavior |

Use the **axes**, not Hofstede's modern-nation scores.

## Individuals vary within a culture

Beneath the culture sits a personal layer, so two members of the same culture still differ:

- **Big Five / OCEAN** personality — agreeableness↔generosity, conscientiousness↔contribution,
  extraversion↔sociability, openness↔innovation, neuroticism↔risk aversion.
- **Maslow** structures the need-priority stack ([04](04-behavior-and-resolution.md)).
- **Moral Foundations (Haidt)** — care / fairness / loyalty / authority / sanctity / liberty;
  how a faith *weights* these becomes its tenets and taboos.

An agent's effective disposition ≈ `culture vector ⊕ faith tenets ⊕ personality ⊕ situation`.

## Where the values come from (don't hand-set them)

The generative principle is **Cultural Materialism (Marvin Harris)**: culture and taboos are
*downstream* of environment + resources. So a culture's vector is **seeded from material
conditions** — a harsh desert breeds restraint, tradition, and tight collectivism; an abundant
trade hub breeds indulgence, secularism, and individualism — not hand-authored. Then it
**drifts**:

- **Inglehart / WVS** — values shift with material security (scarcity → survival values;
  prosperity → self-expression). Ties culture to the economy (06) and the boom-bust loop (05).
- **Institution feedback** (07) — authoritarian institutions raise Hierarchy; communal guilds
  reinforce Collectivism.

So culture is *generated*, then *evolves* with conditions — the same loop as everything else.

## Influences map (what to read, by layer)

- **Parameter spaces:** Hofstede (axes), Schwartz (values), Inglehart/WVS (drift), Big Five,
  Maslow, Moral Foundations.
- **Generative:** Cultural Materialism (Harris).
- **Cooperation / institutions:** Sahlins (reciprocity by social distance = our cohesion),
  Dunbar (~150 ceiling), Ostrom (governing the commons), Olson (collective action /
  "stationary bandit"), Norenzayan ("Big Gods" — religion as a cooperation technology).
- **Scale:** Service (band → tribe → chiefdom → state).
- **Rise & fall (mechanisms):** Turchin (cliodynamics / secular cycles — closest to this whole
  project), Tainter (diminishing returns to complexity), Malthus + Boserup.
- **Geography & trade:** Diamond (biogeography as a generative bias; mind the determinism
  critique), Ricardo (comparative advantage).
- **Methodological ancestor:** Epstein & Axtell, *Sugarscape / Growing Artificial Societies*.

Start with **Turchin, Cultural Materialism, Sugarscape**.

## Status

🟡 Designed. Builds on cohesion ([07](07-cooperation-projects-institutions.md)); feeds economy
(06), institutions (07), and generation (08). Implemented when the culture/institution layer
is built — until then a single hard-coded village cohesion (`0.85`) stands in.
