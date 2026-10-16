# Servicios

Los **Servicios** son motores funcionales especializados. Cada uno observa o transforma un tipo de señal distinto. La regla de arquitectura es evitar duplicar análisis: cada dato tiene una fuente responsable y **Solucionador** es la capa que reúne las conclusiones para decidir actuaciones editoriales.

| Servicio | Función principal |
|---|---|
| [[Clonador]] | Reconstrucción controlada de STAGING a partir de PRO |
| [[Ojeador]] | Observa mercado y Google Merchant/Shopping por categorías y productos |
| [[Comparador]] | Construye perfiles y comparativas usando catálogo propio, snapshots de Ojeador y señales ya calculadas por Analista |
| [[Dependiente]] | Asistente/filtro inteligente para resolver necesidades del cliente usando catálogo, semántica, ranking y conocimiento aprendido |
| [[Academia, Intérprete y Lingüista|Academia-Interprete-y-Linguista]] | Formación de Dependiente y comprensión del lenguaje natural |
| [[Ingeniero]] | Investiga y consolida conocimiento externo L1 técnico, L2 práctico/foros y L3 actualidad, siempre con trazabilidad de fuentes |
| [[Clasificador]] | Propone clasificación semántica y atributos canónicos a partir del catálogo y del conocimiento disponible; solo aplica tras revisión humana |
| [[Analista]] | Interpreta demanda, rendimiento y mercado: Search Console, Analytics, Bing, tendencias, competencia y oportunidades |
| [[Comentarista]] | Aporta evidencia externa y experiencia observada |
| [[Auditor]] | Analiza calidad, coherencia, cobertura y estructura interna del catálogo y de los contenidos; puede contextualizar con snapshots persistidos de otros servicios |
| [[Solucionador]] | Capa de decisión editorial: unifica conclusiones, inventaría cobertura y decide qué crear, ampliar, consolidar o no tocar |

Principio común: distinguir entre **observar**, **proponer**, **validar**, **aplicar** y **publicar**.

### Importador de categorías multidioma (PR #914)

Este **nuevo servicio de Import / Export** permite crear o actualizar categorías WooCommerce en `en-US`, `de-DE` y `nl-NL` y registra su equivalencia española en `seo_relations` mediante `category_translation`. Resuelve el original por `source_slug` (no por ID extranjero ni por coincidencia de nombres), conserva el `_seo_locale` y evita relaciones contradictorias. **No traduce textos automáticamente ni importa productos**. Su despliegue depende de fusionar y validar el [PR #914](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/pull/914).

Manual y esquema: [[Categorías multidioma|Categorias-multidioma]].

La relación entre servicios está documentada en [[Flujo de datos|Flujo-de-datos]].

El inventario de persistencia está documentado en [[Servicios y tablas|Servicios-y-tablas]], donde se identifica qué tablas son canónicas, derivadas, de workflow o de runtime y qué servicio las utiliza.

## Piloto Mercado (2.4.0, en paralelo)

La sección **SEO Taxonomy → Mercado** ofrece tres vistas de lectura: **Dónde estamos (Analista)**, **Cómo mejoramos (Ingeniero)** y **Hacia dónde vamos (Ojeador)**. Son un punto de entrada simplificado que consulta únicamente opciones y snapshots ya guardados. No inicia APIs, workers, nuevas propuestas, sincronizaciones ni borradores al abrirse.

- **Analista:** muestra contadores de tareas y hasta cinco prioridades de su histórico, y enlaza a Informes → Analista y Google Intelligence.
- **Ingeniero:** estado de investigación y avance editorial guardados; enlaza a las pantallas completas de Investigación, Editorial y Landings.
- **Ojeador:** modo de fuente, configuración identificativa y cobertura de snapshots Merchant persistidos; enlaza a Merchant, recomendaciones y posición de precios. Un benchmark Merchant agregado no es una oferta individual.

**Compatibilidad:** los slugs `seo-reports`, `seo-ingeniero` y `seo-ojeador`, sus formularios, tablas, acciones y flujos editoriales se conservan sin modificación. La fase piloto no convierte todavía los tres motores en una cola editorial común. Las decisiones sobre migración funcional y retirada del sistema antiguo requieren una validación posterior.

### Navegación simplificada

**Mercado es la única entrada visual directa** para los tres especialistas. Se retiran las tarjetas de Ingeniero en Contenidos, de Ojeador en Herramientas (incluido su fallback) y la pestaña Analista en Informes. Los informes de Analista, los trabajos de Ingeniero y los informes de Ojeador siguen siendo accesibles mediante los botones de detalle de Mercado y mantienen sus slugs históricos, datos, APIs, cron y acciones. Cuando se abre uno de estos detalles, el submenú activo de WordPress permanece en **Mercado**. Esta decisión no elimina ninguna función de investigación ni cambia los flujos editoriales actuales.

## Piloto Editor: revisión de contenidos y CSV

**TaxoCommerce SEO → Contenidos → Editor** ofrece una bandeja superior de borradores WordPress reales: posts de Solucionador, Ingeniero y Comparador, noticias/entradas manuales y páginas landing reconocidas por su rol. Permite filtrar por tipo y fuente, leer un extracto, editar en las pantallas existentes, publicar individualmente/en lote con confirmación y validaciones o enviar a la papelera (sin borrado definitivo).

También ofrece **CSV UTF-8** para análisis y corrección externa: exportación completa o por lotes de 100, importación de borradores existentes o nuevos, previsualización obligatoria, verificación de ID/hash/relaciones y confirmación. **La importación no publica ni descarta** y no modifica posts ya publicados.

**Alcance controlado:** esta fase sólo reúne borradores ya creados por los flujos heredados o importados; aún no conecta automáticamente las propuestas en tablas de Solucionador, Ingeniero y Comparador para crear borradores sin usar sus aprobaciones originales. No elimina datos ni procesos. Se documenta cada botón y campo en [manual/editor](https://github.com/distribuidordeherramientases-wq/seo-taxonomy/blob/staging/docs/manual/EDITOR.md).
