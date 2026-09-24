SOLUCIONADOR v0.2.0
===================

Objetivo
--------
Convertir senales reales del cliente y del sistema en propuestas concretas de
posts de ayuda, evitando duplicar temas ya cubiertos.

Fuentes
-------
- Dependiente / Interprete: fuente principal. Consultas, intent, objeto,
  contexto, estado, resultados y feedback.
- Analista: refuerza demanda, mercado y huecos de cobertura.
- Auditor: aporta hallazgos/probes compatibles con necesidades de cliente,
  con menor peso.
- Comentarista: aporta problemas o preguntas detectadas en experiencias
  externas almacenadas.
- Posts existentes: inventario editorial para saber si la solucion ya existe.

Regla editorial
---------------
Una propuesta NO es un post. Se guarda como registro de Solucionador con:
- pregunta representativa;
- huella canonica del problema;
- titulo propuesto;
- cobertura existente;
- categorias product_cat propuestas;
- Vocabulary canonico propuesto;
- evidencias y prioridad.

Solo al pulsar "Aprobar y crear borrador" se crea una entrada WordPress en
estado draft. El contenido y el extracto quedan vacios para que se redacten
manualmente.

Clasificacion del borrador
--------------------------
Al crear el borrador se preparan automaticamente:
- Vocabulary mediante wp_seo_object_vocabulary usando los grupos canonicos
  rol, tipo, aplicacion, plataforma y subtipo;
- categorias de producto mediante wp_seo_relations con:
    source_type   = post
    target_type   = product_cat
    relation_type = post_to_category

No se crean relaciones ordinarias post->producto. Los productos concretos se
resuelven dinamicamente por Dependiente a partir de categorias/Vocabulary.

Menu
----
SEO Taxonomy -> Herramientas -> Solucionador

Pestanas
--------
- Resumen
- Propuestas de posts
- Cobertura de posts
- Fuentes

Prueba recomendada en STAGING
-----------------------------
1. Sustituir includes/seo-includes-bootstrap.php.
2. Copiar la carpeta includes/solucionador/ completa.
3. Abrir Herramientas -> Solucionador.
4. Pulsar "Reanalizar fuentes".
5. Revisar varias propuestas: pregunta, titulo, categorias y Vocabulary.
6. Aprobar UNA propuesta de prueba.
7. Comprobar que se crea un post draft con:
   - titulo propuesto;
   - contenido vacio;
   - categorias relacionadas;
   - Vocabulary ya seleccionado.
8. Rellenar contenido y publicar solo si la propuesta es correcta.
9. Comprobar que Solucionador marca el tema como cubierto tras publicarlo.

Migracion
---------
Si existia Solucionador v0.1.x, dbDelta amplia sus tablas al esquema v0.2.0.
No es necesario borrar las tablas antes de instalar esta version.
