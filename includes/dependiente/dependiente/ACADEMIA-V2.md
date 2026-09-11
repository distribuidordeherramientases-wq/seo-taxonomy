# Dependiente 0.2.12 acumulativo · Academia v2.1


## v0.2.12 · Separacion canonica de conocimiento editorial y FAQ

- L5 evalua exclusivamente el carril editorial (posts/paginas) recuperado por Vocabulary y relaciones editoriales.
- L6 evalua exclusivamente FAQs owner-first de producto/categoria; una FAQ no crea ni hereda relacion con posts o paginas.
- El fallback textual global de FAQ queda desactivado por defecto: si no se identifica owner, no se fabrica una asociacion FAQ por similitud textual.
- El API expone `related_editorial` y `related_faq` como ramas paralelas y conserva `related` solo como mezcla compatible para la interfaz.
- La recuperacion editorial prioriza coincidencias exactas de las rutas Vocabulary ya interpretadas por el parser.
- No requiere reset global: se conserva el snapshot valido anterior y basta repreparar la leccion bloqueada.


## Objetivo

Academia v2.1 entrena al Dependiente sobre una fotografia fija del catalogo y del contenido canonico de PRO. Vocabulary es el diccionario comun para productos, categorias, posts y paginas; las FAQs cuelgan exclusivamente de su owner producto/categoria mediante object_type/object_id.

## Contrato acumulativo de versión

- Cada paquete completo de Dependiente incorpora las correcciones de las versiones anteriores; no requiere instalar 0.2.1, 0.2.2, etc. por separado.
- 0.2.12 incluye todo lo acumulado hasta 0.2.11. Mantiene el observatorio de Aprendizaje y corrige la separación entre contenido editorial y FAQs owner-first para L5/L6 y para la respuesta real del Dependiente.
- 0.2.11 incluye todo lo acumulado hasta 0.2.10. Mantiene la verificación exacta del universo indexable de 0.2.10 y añade el observatorio de Aprendizaje / Calidad de formación sin alterar el motor ni el conocimiento.
- Una ruta semántica de catálogo debe llegar a Vocabulary/`seo_object_vocabulary` antes de considerar suficiente el contenido editorial. Landings, posts o FAQs son apoyo y no pueden bloquear productos cuando la consulta pide catálogo.
- Los slugs de Vocabulary con guion bajo y las rutas semánticas normalizadas con espacios se resuelven al mismo ID canónico.

## Observatorio de aprendizaje y calidad de formación · 0.2.11

- La pestaña `Aprendizaje` incorpora `Resumen`, `Evolución`, `Calidad de formación` y `Fallos y fuentes`.
- El informe separa evidencia evaluada directamente de contexto correlacionado para no atribuir causalidad falsa a categorías, atributos, etiquetas, Vocabulary, hubs o clusters.
- Los fallos se agrupan por fuente, dimensión, módulo y diagnóstico (`retrieval_gap`, `ranking_gap`, etc.) y se priorizan para revisión humana.
- El detalle de un fallo muestra la pregunta, verdad esperada, resultados devueltos y la fuente actual en PRO (producto/categoría/Vocabulary cuando puede reconstruirse).
- Una fuente marcada para revisión es una señal estadística, no una corrección automática ni una afirmación de que el contenido esté mal.
- El JSON `progreso` sube a schema v3 e incluye `training_quality`, con detalle de los fallos y procedencia suficiente para auditar contenidos fuera de WordPress.
- Esta telemetría es de solo lectura: no reabre lecciones, no resetea conocimiento, no inicia entrenamiento y no modifica fuentes.

## Quality gate y repetición

- Una lección solo queda `completed` cuando ha respondido todos sus ejercicios, no tiene errores técnicos y supera `min_pass_any`.
- Si termina los ejercicios y no supera el gate queda `needs_training`. No crea `snapshot_after`, no promociona reglas staged y no desbloquea la siguiente lección.
- La formación automática se detiene de forma limpia en `needs_training`; el usuario decide cuándo repreparar la lección.
- Se conserva la reconciliación conservadora de 0.2.9 para lecciones antiguas `completed` con `quality_gate.passed=false`; el contador global histórico de snapshots no se decrementa.
- La verificación de reindexación 0.2.10 compara IDs exactos de productos indexables, informa faltantes/sobrantes y no trata un producto publicado `hidden` como faltante.
- Los informes de lección y progreso exponen el estado del gate para distinguir progreso observado de aprobación real.

## Aula aislada

- Las busquedas normales de clientes usan solo conocimiento activo.
- L1-L7 se ejecutan en un aula aislada que puede consultar conocimiento activo + reglas `academy_stage` de la leccion actual.
- Las reglas `academy_stage` se preparan desde fuentes canonicas y nunca son visibles para clientes mientras sigan staged.
- Al finalizar una leccion, solo se promocionan a `academy`/activas si supera el quality gate y no hay errores tecnicos.
- L8 es un examen cerrado y usa solo conocimiento activo.

## Curriculo

1. Mapa del catalogo: categorias, jerarquia y Vocabulary asociado.
2. Inventario representativo: reconocimiento del catalogo con diversidad semantica.
3. TIPO y ROL: rutas canonicas del Vocabulary.
4. Caracteristicas y necesidades: progresion foundation -> combined -> deep con aplicaciones, plataformas, subtipos, atributos y etiquetas depuradas.
5. Posts y paginas: contenido editorial conectado mediante Vocabulary comun.
6. FAQs contextualizadas: cada FAQ se interpreta con su propietario (`object_type` + `object_id`) y hereda su contexto semantico.
7. Relaciones cruzadas: conceptos compartidos entre catalogo y contenido.
8. Examen integrado y regresion: muestra de L1-L7 sin ayuda de `academy_stage`.

## Criterios del generador L4

- Primero crea ejercicios simples con una relacion semantica y una caracteristica.
- Despues combina varias restricciones.
- Los ejercicios profundos pueden incorporar una etiqueta cuando aporta informacion util.
- Se descartan como etiquetas docentes los valores puramente numericos, medidas aisladas y etiquetas redundantes con atributos o Vocabulary ya presentes.
- La seleccion se distribuye de forma determinista por todo el catalogo para evitar que el temario quede concentrado en los primeros IDs.

## Diagnostico de fallos

Cada ejecucion puede etiquetar el problema como:

- `mastered`: conocimiento resuelto.
- `parser_gap`: Dependiente no interpreta bien la consulta.
- `retrieval_gap`: interpreta pero no recupera el objeto esperado.
- `ranking_gap`: recupera candidatos pero el correcto no queda en Top 8.
- `clarification_gap`: encuentra la respuesta pero pide una aclaracion innecesaria.
- `curriculum_invalid`: la pregunta preparada por Academia debe revisarse.
- `technical_error`: fallo tecnico.

## Exportacion del temario

El JSON de una leccion puede descargarse una vez preparada, incluso antes de ejecutarla. Incluye preguntas futuras (`run: null`), ground truth, dificultad, diagnosticos cuando existen y un bloque `curriculum_audit` con distribucion de tipos de pregunta, niveles de dificultad y tipos de feature.

## FAQs

`wp_seo_faq.object_type` y `object_id` identifican al propietario/contexto principal de la FAQ:

- `1`: pagina / hub.
- `2`: categoria de producto.
- `3`: producto.

Academia resuelve primero el propietario y despues usa su Vocabulary como contexto para la pregunta/respuesta.

## Seguridad

- Cargar la pantalla de Academia no inicia la formacion.
- El usuario inicia o reanuda la formacion; el Gestor de procesos solo continua un proceso ya iniciado.
- El aprendizaje de Academia no se escribe en el log de clientes ni activa aprendizaje observacional.
- Un quality gate fallido no promociona reglas staged a conocimiento operativo.
