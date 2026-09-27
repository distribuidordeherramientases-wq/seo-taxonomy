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
- Auditor

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

Ingeniero añade una capa de **conocimiento técnico externo por categoría** sin mezclarla con el índice comercial de Dependiente.

### Lección 1 · Documentación técnica

V1 investiga categorías WooCommerce con productos y prioriza inicialmente las de mayor catálogo.

Controles:

- **Categorías piloto**: número de categorías a preparar.
- **Preparar lección** [Proceso]: crea la cola sin realizar todavía consultas externas.
- **Iniciar / continuar investigación** [Proceso][API]: entrega el trabajo al Gestor de procesos.
- **Detener** [Proceso].
- **Exportar JSON** [Exporta].
- **Revisar conocimiento** [Consulta]: abre fuentes, evidencia, confianza y estado.
- **Reinvestigar categoría** [Proceso][API]: encola la categoría de nuevo; no bloquea la petición del administrador.
- **Aprobar / Mantener revisión / Rechazar** [Guarda]: revisión humana del conocimiento consolidado.

KPIs visibles: categorías con productos, investigadas, pendientes, en revisión, errores, fuentes, conocimientos, confianza media y última investigación.

### Búsqueda y presupuesto

V1 usa un provider desacoplado y la implementación SerpApi/Google web. Reutiliza la credencial existente de Ojeador, pero mantiene un límite local independiente para Ingeniero.

Campos:

- Límite mensual local.
- Resultados por consulta.
- Consultas por categoría.
- Páginas HTML a leer.
- **Guardar configuración** [Guarda].

Los PDFs se detectan y quedan como `pdf_pending`; v1 no añade un parser pesado.

### Calidad y trazabilidad

- organismo/normativa: confianza alta;
- documentación técnica: media-alta;
- web especializada: media;
- comunidad/opinión: baja.

Una fuente de confianza alta puede sostener conocimiento activo. Fuentes técnicas no oficiales requieren confirmación entre fuentes para activarse automáticamente. Lo dudoso queda en **revisar**.

**Política editorial:** Ingeniero separa siempre la evidencia de la redacción. Las frases de origen solo pueden conservarse como evidencia breve y con enlace a la fuente. El resumen/contenido generado debe ser una síntesis propia, no una copia ni una concatenación de textos externos.

La relación es siempre: `conocimiento → source_ids → URL/fuente`. La pantalla de revisión muestra el enlace original junto a cada evidencia para poder comprobarla.

No se guardan copias completas de páginas o manuales.

### Separación de capas

- `seo_dependiente_index`: nuestro catálogo.
- `seo_ingeniero_knowledge`: conocimiento técnico externo.

La función `SEO_Ingeniero::active_knowledge(term_id)` expone conocimiento aprobado para futuras integraciones. En v1 **no se inyecta todavía en las respuestas públicas de Dependiente**.

### Lección 2 · Experiencia práctica

Preparada pero desactivada en v1. Su finalidad futura es recoger foros/comunidades y mantener esa evidencia marcada como experiencia/opinión, no como hecho técnico.
## Conocimiento

- **Exportar conocimiento** [Exporta].
- **Importar y fusionar conocimiento** [Modifica].
- checkbox obligatorio **Confirmo que quiero fusionar este conocimiento con el cerebro local**.
- secciones informativas: Cómo se protege producción, Identidad del cerebro y Reglas excluidas del paquete.

## Auditor

Auditor permite ejecutar bloques independientes para evitar recorrer siempre todo el catálogo:

- Productos · contenido e identidad.
- Categorías y arquitectura.
- Posts.
- Páginas y landings.
- FAQs.
- Motor / índice de Dependiente.

Cada bloque puede ejecutarse, revisar su último informe y exportar JSON.

También se conservan las auditorías profundas/globales:

- **Auditoría completa de catálogo**: recorre todas las capas.
- **Academia / Estudiante**: lecciones, runs, promoción de reglas, snapshots y aprendizaje.

Las auditorías son de solo lectura. Los hallazgos son señales de revisión, no órdenes automáticas de cambio.
