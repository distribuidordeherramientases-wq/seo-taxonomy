# TaxoCommerce Dependiente 0.2.1

Plugin de la familia TaxoCommerce que conserva el diseño de las diez primeras lecciones del Dependiente anterior, simplificando la arquitectura: **un único servicio Dependiente**, sin presentar Academia ni Entrenador como servicios separados.

## Flujo de cada lección

Cada L1-L10 tiene dos fases obligatorias:

1. **Análisis / foto fija**: se recorren las fuentes que la lección debe estudiar y se copian a `tcd_lesson_items`. Al terminar se sella la foto con número de fuentes y firma SHA-256 global.
2. **Aprendizaje**: Dependiente estudia exclusivamente los elementos congelados. Cada fila termina como `learned` o `unlearned`, con motivo y fecha.

Una modificación posterior del catálogo no cambia silenciosamente una lección ya iniciada. Detener/reanudar conserva el mismo snapshot. Reiniciar es explícito y solo vacía tablas propias de aprendizaje.

## Las diez lecciones

- **L1 Mapa del catálogo**: categorías, jerarquía y Vocabulary de categoría.
- **L2 Inventario y producto**: qué es cada producto, SKU, marca, categorías y Vocabulary.
- **L3 TIPO y ROL**: conceptos canónicos y productos asociados.
- **L4 Características y necesidades**: atributos, aplicación, plataforma, subtipo y etiquetas.
- **L5 Posts y páginas**: contenido editorial y Vocabulary compartido.
- **L6 FAQs contextualizadas**: tabla canónica `seo_faq`, categoría/propietario, pregunta, respuesta y Vocabulary.
- **L7 Relaciones cruzadas**: conceptos compartidos entre catálogo y contenido editorial.
- **L8 Examen integrado**: revisión del conocimiento L1-L7.
- **L9 Necesidades y productos**: lenguaje natural construido desde productos reales y sus dimensiones semánticas.
- **L10 Consolidación y deuda**: revisión L1-L9 para localizar conocimiento pendiente/incoherente.

## Auditoría y JSON

La pantalla Dependiente muestra por lección: fase, estado de foto fija, número de fuentes, firma, aprendidos y no aprendidos.

Se puede descargar:
- JSON de una lección.
- JSON completo del run.

Cada JSON contiene el temario, metadatos del snapshot, material exacto estudiado y el resultado de cada elemento. La exportación se transmite por lotes para no cargar miles de productos en memoria de una sola vez.

## Datos

Tablas propias:
- `tcd_knowledge`: conocimiento final.
- `tcd_lesson_snapshots`: cabecera y firma de la foto fija de cada lección.
- `tcd_lesson_items`: copia inmutable del material y resultado.

Fuentes canónicas del ecosistema:
- WordPress/WooCommerce para productos, categorías, posts y páginas.
- `seo_vocabulary` / `seo_object_vocabulary` para semántica.
- `seo_faq` para FAQs.

## Qué se ha eliminado

No se recuperan como servicios independientes: Academia, Entrenador, Laboratorio, Actualización continua ni reciclado automático. Tampoco se copia el enorme controlador histórico. Se conserva lo útil: currículo, preparación previa, separación preparación/ejecución, trazabilidad, control de CPU y exportación.

## Límite actual

El motor público del plugin independiente todavía debe seguir acercándose al Dependiente V3 antiguo. Esta versión corrige primero la **calidad y auditabilidad del aprendizaje**, para que el motor pueda consumir conocimiento fiable y reproducible.


## Prioridad de trabajo

El usuario controla la presión sobre el servidor con un único deslizador de **Prioridad 1–5**:
- 1: Suave.
- 2: Baja.
- 3: Normal (recomendada).
- 4: Alta.
- 5: Máxima.

No se exige una métrica global del hosting. Cada lote mide su propio tiempo, CPU de proceso mediante `getrusage()` cuando está disponible, pico de memoria y consultas. El tamaño del siguiente lote se adapta en una escalera discreta; si el coste sube, se reduce aproximadamente a la mitad. Si `getrusage()` no está disponible, el proceso no se bloquea y usa el perfil estático de prioridad.
