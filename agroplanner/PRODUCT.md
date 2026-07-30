# Product

## Register

product

## Users

Productores agropecuarios argentinos (dueños/responsables de campo) que gestionan
una o varias actividades: agricultura, ganadería y tambo. No son necesariamente
técnicos en software: esperan que la herramienta sea clara y directa. El mismo
usuario cumple varios roles —carga las operaciones del día a día (gastos, insumos,
labores, producción) y también analiza la rentabilidad para decidir.

Contexto de uso: **principalmente escritorio** (carga y análisis sentado frente a
la compu), con un uso secundario en **celular en el campo** (es una PWA con modo
offline). El idioma es español rioplatense y los números van en formato es-AR.

## Product Purpose

Consolidar toda la gestión del campo en un solo lugar y traducirla en decisiones.
El productor registra costos, labores, insumos y producción, y la app le devuelve
**rentabilidad accionable**: margen neto, ROI, costo por hectárea, costo por kg y
rinde de indiferencia, desglosados por lote, cultivo y campaña.

El usuario necesita las cuatro cosas a la vez: controlar costos y márgenes,
registrar rápido y sin fricción, comparar rentabilidad entre cultivos/lotes, y
tener agricultura + ganadería + tambo consolidados como fuente de verdad.

Éxito = el productor abre la app y en segundos sabe **cuánto gana o pierde y
dónde**, con datos en los que confía, y puede actuar (qué sembrar, qué ajustar).

## Brand Personality

Mezcla de **profesional y confiable** con **práctico y de campo**, sin caer en lo
rústico-industrial ni en lo frío-corporativo. Tres palabras: confiable, preciso,
práctico.

Voz: clara, directa, en castellano, con el número de decisión siempre al frente.
Debe sentirse como una herramienta financiera sólida que igual entiende el barro
del campo. Meta emocional: **confianza y control** — "esto maneja mi plata y la
entiendo de un vistazo".

## Anti-references

- **Planilla de Excel / software contable viejo**: tablas grises infinitas, frío,
  abrumador, sin jerarquía. El sistema de los 2000.
- **App genérica hecha por IA**: gradient text, tarjetas idénticas repetidas,
  eyebrows en mayúscula sobre cada sección, glassmorphism decorativo porque sí.
- **Estética infantil**: colores chillones, ilustraciones caricaturescas, emojis
  por todos lados que le quitan seriedad a algo que maneja dinero real.
- **Pantallas recargadas/saturadas**: mil cosas a la vez sin jerarquía, que marean
  y esconden lo que importa.

## Design Principles

1. **El número manda.** Cada pantalla pone el dato de decisión (margen, costo,
   rinde, ROI) al frente. Lo decorativo nunca compite con lo financiero.
2. **Confianza por claridad, no por adornos.** La sensación premium viene de la
   precisión, el contraste y el orden — no de efectos ni glow gratuito.
3. **Carga sin fricción.** Registrar un gasto/operación/producción es trabajo
   diario y repetitivo: tiene que ser rápido, predecible y perdonar errores.
4. **Densidad legible.** Mostrar mucha info real sin abrumar: jerarquía,
   agrupación y ritmo visual antes que tablas planas.
5. **Honestidad del dato.** Estados vacíos, valores en $0 y supuestos (ej. dólar
   de referencia, precios derivados) se muestran explícitos, nunca escondidos.

## Accessibility & Inclusion

- **WCAG AA como piso**: texto de cuerpo ≥4.5:1, texto grande ≥3:1. Crítico porque
  hay usuarios mayores y a veces uso bajo luz fuerte. El tema oscuro actual debe
  mantener contraste real: evitar gris claro sobre gris/superficie tintada.
- **Reduced motion**: toda microinteracción necesita alternativa con
  `prefers-reduced-motion: reduce`.
- **Touch targets ≥44px** y tablas con `data-label` legibles en mobile, por el uso
  PWA en el campo.
- Números siempre en formato es-AR; etiquetas y errores en español claro.
