# Dependiente

Ruta: **SEO Taxonomy → Dependiente**

## Pestañas

- Configuración
- Informe
- Aprendizaje
- Academia
- Intérprete
- Ingeniero
- Conocimiento
- Auditor Academia

## Configuración

### Estado del catálogo

- **Reindexar catálogo completo** [Proceso]: reconstruye el índice derivado usado por Dependiente.
- **Vaciar índice** [Elimina]: elimina el índice, no los productos WooCommerce.

### Configuración del piloto

Campos:

- Resultados por página.
- Tarjetas visuales por bloque.
- Correo para consultas no resueltas.
- Metadatos comerciales adicionales.
- Página pública.

Imágenes de las cuatro acciones:

- Arreglar o hacer algo.
- Buscar un producto.
- Elegir una herramienta.
- Comparar opciones.

Para cada imagen pueden aparecer:

- **Elegir en Medios** [Guarda].
- **Usar imagen incluida** [Guarda].

**Guardar configuración** [Guarda] persiste los ajustes del piloto.

La pantalla también muestra Imágenes de navegación, Fuentes de datos detectadas y Lógica del Dependiente.

### Comparador público

El modo **Comparar opciones** permite seleccionar entre **2 y 6 productos** del catálogo.

- **Comparar** abre la tabla comparativa con precio, marca, referencia, disponibilidad, peso, dimensiones, categorías, Vocabulary y atributos disponibles.
- **Vaciar** elimina la selección actual.
- **Descargar PDF** [Exporta] genera la comparación visible como documento PDF sin crear pedido, factura, proforma ni presupuesto.
- El PDF reutiliza el motor Dompdf del módulo **Facturas y presupuestos** y se genera en **A4 apaisado** para mantener legibles hasta seis productos.
- Cuando existe identidad corporativa configurada en Facturas y presupuestos, el PDF reutiliza nombre comercial y logotipo.

### Zona peligrosa

**Borrar conocimiento del Dependiente** [Elimina] reinicia conocimiento aprendido. Exige la confirmación:

- checkbox **Sí, borrar el conocimiento y empezar desde cero**.
- **Cancelar**: abandona la operación.

## Informe

Permite cambiar el periodo mediante los botones disponibles y descargar **Informe JSON** [Exporta].

Bloques visibles:

- Consultas más repetidas.
- Intenciones detectadas.
- Objetos detectados.
- Contextos detectados.
- Estrategias de búsqueda.
- Términos todavía no resueltos.
- Vigilancia de cobertura de catálogo.
- Productos que más está ofreciendo Dependiente.
- Estado de la semántica.
- Qué busca la gente y qué le ofrecemos.

## Aprendizaje

Secciones:

- Candidatos pendientes.
- Aprendizajes activos.
- Rechazados.

Acciones:

- **Aprobar** [Guarda]: incorpora el candidato al conocimiento activo.
- **Rechazar** [Guarda]: lo marca como no aceptado.

## Academia

Pantalla **Academia del Dependiente**.

Controles:

- **Descargar curso completo (JSON)** [Exporta].
- **Descargar progreso** [Exporta].
- **Descargar informe completo** [Exporta].
- **Exportar preguntas · CSV** [Exporta]: descarga todas las preguntas activas del currículo de Entrenador, estén aprendidas, suspendidas o todavía pendientes. Incluye el último run disponible, respuesta observada, `evaluation_status`, `evaluation_score`, estado `aprendida` y valoración editorial independiente.
- **Importar preguntas · CSV** [Guarda]: importación aditiva e idempotente por `question_hash`. Añade preguntas nuevas al currículo pero no importa ni fabrica runs, respuestas, evaluaciones o puntuaciones.

### CSV de preguntas del Entrenador

El intercambio CSV separa explícitamente el valor de la pregunta del resultado de aprendizaje de Dependiente.

Estados de `aprendida`:

- `si`: el último `evaluation_status` empieza por `pass_`;
- `no`: existe evaluación pero no ha superado el aprendizaje;
- `pendiente`: todavía no existe evaluación.

`editorial_value` se calcula de forma independiente:

- `useful`: pregunta con valor práctico potencial;
- `not_useful`: pregunta de identidad/listado del catálogo, definición mecánica u otro ruido de entrenamiento.

Por tanto, una pregunta útil que Dependiente suspenda **se sigue exportando**. Esto permite reutilizarla posteriormente como materia prima para FAQ v2 u otros procesos editoriales sin modificar el comportamiento actual de Solucionador.

La exportación incluye tres ámbitos, identificados en `question_scope`:

- `curriculum`: preguntas del currículo oficial;
- `laboratory`: lotes de Academia con `lesson_key=lab_*`;
- `manual`: preguntas manuales o importadas fuera del currículo oficial.

Columnas principales: `question_id`, `question_hash`, `question_scope`, categoría, producto, pregunta, respuesta observada de Dependiente, tipo, lección, run, estado, evaluación, puntuación, aprendida, valor editorial, motivo de descarte, fuente y `expected_json`.

La importación no sobrescribe resultados existentes. Las columnas de respuesta, aprendizaje y calidad son de solo lectura: sólo se generan mediante ejecuciones reales de Academia/Dependiente. Los lotes de Laboratorio y preguntas manuales también pueden reimportarse sin fabricar runs ni evaluaciones.

### Laboratorio de preguntas

Cuando está habilitado:

- campo **Preguntas**.
- **O cargar archivo**.
- selector **Modo por defecto**.
- **Preparar nuevo lote** [Proceso].
- **Descargar resultados JSON** [Exporta].
- tabla Pregunta / Estado / Respuesta.

También muestra Modo de formación, Progreso, Informe completo y tabla Módulo / Pregunta / Evaluación / Respuesta.

## Intérprete

- **Probar una pregunta** [Consulta]: ejecuta una prueba puntual.
- bloque **Lingüista · formación del Intérprete**.
- durante **L9 · Consolidación desde Dependiente**, panel **Diagnóstico en vivo** con fase actual, reglas utilizables/en deuda, conservación semántica, evidencias de ruido, patrones consolidados y regresión.
- **Últimos casos de L9** [Consulta]: buffer circular de hasta 30 casos; no crea un log ilimitado ni altera el aprendizaje.
- **Descargar estado L9 JSON** [Exportación]: disponible mientras L9 sigue ejecutándose, con métricas, memoria y últimos diagnósticos.
- **Pausar formación** [Proceso].
- **Reanudar formación** [Proceso].
- **Iniciar formación Lingüista** [Proceso].
- **Reentrenar desde L1** [Proceso].
- tabla Lección / Estado / Progreso / Aprendido-revisado / Acción.
- **Reentrenar desde aquí** [Proceso] para una lección concreta.

## Ingeniero

Ingeniero es ahora un servicio independiente dentro de **SEO Taxonomy → Contenidos → Ingeniero**. Dependiente no contiene su interfaz operativa.

Consulta el manual completo en **Ingeniero**, donde se documentan Investigación, Editorial, Datos y fuentes, Pruebas, todos sus campos, botones, filtros y estados.

## Conocimiento

- **Exportar conocimiento** [Exporta].
- **Importar y fusionar conocimiento** [Modifica].
- checkbox obligatorio **Confirmo que quiero fusionar este conocimiento con el cerebro local**.
- secciones informativas: Cómo se protege producción, Identidad del cerebro y Reglas excluidas del paquete.

## Auditor

Dentro de Dependiente sólo permanece **Auditor Academia**, dedicado al aprendizaje, Entrenador, runs, reglas y snapshots.

Las auditorías de Productos, Categorías, Posts, Páginas/Landings y FAQs se gestionan en **SEO Taxonomy → Contenidos → Auditor**. Los chequeos técnicos de motor/índice pertenecen a **Herramientas → Plugin Validation**.

Consulta el manual completo de **Auditor** para cada botón, salida JSON y alcance.

