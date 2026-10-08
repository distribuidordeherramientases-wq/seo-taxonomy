# Analista

Capa de **análisis estratégico de demanda, rendimiento y mercado**.

Combina señales de Google, Bing, GA4, Search Console, tendencias, mercado/competencia, campañas, evolución del tráfico, literatura y otras fuentes ya integradas.

Su función es detectar:

- contenidos y URLs con tracción;
- contenidos con impresiones pero bajo rendimiento;
- temas o consultas emergentes;
- oportunidades de crecimiento;
- activos que conviene ampliar o replicar por intención;
- contenidos con poco seguimiento que pueden requerir mejora;
- señales de mercado relevantes para catálogo y contenido.

Analista no debe modificar por sí solo el catálogo ni crear contenido. Produce **conclusiones y oportunidades**.

La separación es:

- **Auditor** analiza la estructura y calidad interna.
- **Analista** interpreta comportamiento, demanda y mercado.
- **Solucionador** utiliza ambas capas para tomar decisiones editoriales.

## Informe competitivo profundo

Analista puede generar, bajo demanda, un **informe competitivo profundo** a partir de los dominios configurados en la vista Comparación.

La comparación combina tres capas que no deben confundirse:

1. **Datos privados propios**: Search Console, GA4 y Bing sólo para el dominio conectado.
2. **Evidencia pública competitiva**: SERP, HTML público, robots, sitemap, estructura observable y señales comerciales.
3. **Contexto de mercado**: Google Trends y las señales ya disponibles en Ojeador/Analista, sin convertirlas en una estimación de tráfico de competidores.

El adaptador de rankings usa la conexión compartida **SerpApi → ScraperAPI**. El informe no se ejecuta al abrir la pantalla: requiere una acción explícita del usuario.

La salida ofrece puntuaciones comparables en SERP, arquitectura, producto, enlazado, confianza, UX y SEO técnico observable. Cada puntuación lleva una confianza de evidencia y los datos ausentes se mantienen como **N/D**, no como cero.

La finalidad es producir una matriz competitiva, detectar fortalezas y brechas y traducirlas a un plan priorizado sin inventar autoridad, tráfico, Core Web Vitals o datos privados de terceros.

