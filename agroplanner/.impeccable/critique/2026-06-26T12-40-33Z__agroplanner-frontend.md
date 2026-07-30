---
target: agroplanner frontend (toda la UI)
total_score: 24
p0_count: 0
p1_count: 3
timestamp: 2026-06-26T12-40-33Z
slug: agroplanner-frontend
---
# Critique — agroplanner frontend (panel general + shell + carga de costos)

## Design Health Score

| # | Heuristic | Score | Key Issue |
|---|-----------|-------|-----------|
| 1 | Visibility of System Status | 3 | Flash + offline banner + active nav OK; sin estado "guardando" ni skeletons (full reload) |
| 2 | Match System / Real World | 3 | Español + dominio fuerte; métricas clave (rinde indiferencia, costo/ha) sin explicación inline |
| 3 | User Control and Freedom | 3 | Cancel/confirm presentes; delete es hard-delete sin undo |
| 4 | Consistency and Standards | 2 | Estilos inline por página; botones/badges reimplementados en vez de componentes |
| 5 | Error Prevention | 3 | confirm() + selects/date; destructivo solo con confirm nativo |
| 6 | Recognition Rather Than Recall | 3 | Nav con texto+icono, dropdowns con stock; bien |
| 7 | Flexibility and Efficiency | 2 | Sin atajos, sin acciones masivas, recarga completa en cada filtro |
| 8 | Aesthetic and Minimalist | 2 | Glassmorphism como default + glow/gradientes decorativos + sobrecarga de tarjetas |
| 9 | Error Recovery | 1 | `die("Error: ".$e)` vuelca error técnico crudo y pierde el formulario |
| 10 | Help and Documentation | 2 | Sin tooltips de métricas, sin ayuda contextual; buen empty-state inicial |
| **Total** | | **24/40** | **Aceptable — base sólida, faltan UX gaps clave** |

## Anti-Patterns Verdict

**LLM assessment:** No grita "hecho por IA", pero tiene tells concretos: **gradient text** en el título de página (`background-clip:text` blanco→gris en `h1.page-title`), **glassmorphism como default** (toda la app sobre `glass-panel` con `blur(20px)`), y glows radiales decorativos en el body. Esos tres son justo dos de tus anti-referencias (app genérica de IA / recargado).

**Deterministic scan (detect.mjs):** 3 findings — bounce/elastic easing en index.php:188 (`cubic-bezier(0.175,0.885,0.32,1.275)`); fuente "overused" Inter en header.php:17 (aceptable en product UI); `font-family:Arial` en operaciones.php:1204 (falso positivo: es la preview tipo Excel de la receta).

**Visual overlays:** no disponible — sin automatización de browser en este entorno; sin overlay confiable. Señal de fallback reportada.

## Overall Impression
Base técnica sólida y coherente vía tokens CSS, dominio bien hablado, responsive real (tablas → cards en mobile). El problema mayor es estratégico: el aesthetic (vidrio + glow + gradientes, paleta SaaS-cripto oscura) lee "moderno-tecnológico", no "confiable + de campo" como pediste. La mayor oportunidad: aterrizar el tono y cerrar los gaps de error/accesibilidad.

## What's Working
1. **Sistema de tokens coherente** (`:root` con accent/danger/warning/surfaces) — base real para escalar.
2. **Responsive estructural** — tablas que se vuelven cards con `data-label` en ≤768px, sidebar colapsable. Bien resuelto.
3. **Dominio bien hablado** — lote/campaña/labor/insumo, $ es-AR, empty-state "Comienza tu Planificación" que enseña.

## Priority Issues

- **[P1] Mismatch tonal: el look no es "confiable + de campo".** Glassmorphism omnipresente + glow + gradientes = SaaS genérico, una de tus anti-referencias. **Fix:** vidrio/blur solo donde aporta, superficies más sólidas, paleta más aterrizada, jerarquía por contraste y no por efecto. **Cmd:** /impeccable quieter.
- **[P1] Recuperación de errores rota.** operaciones.php hace `die("Error: ".$e->getMessage())`: pantalla en blanco, error técnico, formulario perdido. **Fix:** flash de error, preservar inputs, estado "guardando". **Cmd:** /impeccable harden.
- **[P1] Tells de "IA/genérico".** Gradient text en `h1.page-title` (ban absoluto) + decoraciones de glow. **Fix:** título a color sólido, quitar gradientes decorativos. **Cmd:** /impeccable typeset.
- **[P2] Accesibilidad por debajo de AA.** Sin `prefers-reduced-motion`; texto 0.65–0.72rem (ilegible para productor mayor / bajo sol); significado solo por color (rojo/verde) en márgenes; muted sobre superficies tintadas. **Fix:** subir tamaños mínimos, +/- e íconos junto al color, media query reduced-motion, verificar contraste. **Cmd:** /impeccable audit.
- **[P2] Sprawl de estilos inline / vocabulario inconsistente.** Botones Excel/PDF y badges con estilos inline repetidos por página. **Fix:** extraer componentes/tokens (`.btn-excel`, `.btn-pdf`, `.kpi`). **Cmd:** /impeccable extract.

## Persona Red Flags

**Alex (power user):** sin atajos de teclado; borrado de a uno con confirm cada vez; recarga completa de página en cada cambio de filtro; sin command palette ni acciones masivas.

**Sam (a11y):** significado por color en márgenes/costos; foco visible inconsistente en inputs estilizados inline; sin reduced-motion; gradient text con `text-fill-color:transparent` baja contraste del título.

**Don Productor (persona del proyecto — 60+, poco técnico, a veces bajo el sol):** texto de 0.65rem en tickers/labels ilegible; métricas sin explicar ("Indiferencia" ¿qué es?); blur/vidrio y paleta oscura pierden legibilidad a pleno sol en la camioneta.

## Minor Observations
- Bounce easing en index.php:188 → usar ease-out-quart/expo.
- `fadeInSlideUp 0.6s` en `.main-content` corre en cada navegación (product no quiere page-load sequences).
- Scrollbars custom finas: OK, no rompen.
- Inter: aceptable en product UI; si querés personalidad, una sans con más carácter para títulos.

## Questions to Consider
- ¿Qué versión de "confiable + de campo" se ve sólida sin caer en rústico? ¿Menos vidrio, más papel/tierra?
- ¿Las métricas financieras necesitan un tooltip de 1 línea que las explique?
- ¿Vale una capa de "modo claro / alto contraste" para uso a pleno sol?
