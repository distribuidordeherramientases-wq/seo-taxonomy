SOLUCIONADOR v0.2.4
===================

Objetivo
--------
Convertir preguntas reales y gaps editoriales en propuestas concretas de posts
de ayuda, evitando crear contenidos duplicados o transformar incidencias tecnicas
internas en preguntas para clientes.

Fuentes y responsabilidades
---------------------------
- Dependiente / Interprete: fuente principal. Consultas, intent, objeto,
  contexto, estado, resultados y feedback. Puede originar propuestas.
- Comentarista: solo las preguntas explicitas pueden originar propuestas.
  Problemas o limitaciones narrados en reviews actuan como refuerzo; valoraciones
  positivas, prefijos editoriales y resúmenes no se convierten en preguntas.
- Analista:
  * busquedas internas: pueden originar propuestas;
  * plan de decision: normalmente solo refuerza necesidades ya detectadas;
  * MEJORAR_PRODUCTO, IMPULSAR_CATEGORIA y decisiones estructurales no se
    convierten automaticamente en preguntas de cliente.
- Auditor:
  * probes conductuales con una consulta real: pueden originar propuestas;
  * findings: solo una allowlist de gaps editoriales/search/FAQ;
  * incidencias tecnicas de indice, schema, excerpt, servidor, BD, etc. se excluyen.
- Posts existentes: inventario editorial para saber si la solucion ya existe.

Cambios principales v0.2.4
--------------------------
1. Dependiente V3 registra cada consulta publica en seo_dependiente_search_log.
2. El registro V3 usa la tabla existente, no cambia el esquema y no activa
   SEO_Dependiente_Learning legacy.
3. El JSON semantico del log conserva acciones y hits relevantes de V3 para
   trazabilidad de Interprete/Solucionador.
4. Solucionador combina siempre el log estructurado de Dependiente con el
   historico de busquedas internas conservado por Analista y deduplica ambos.
5. zero_results se calcula con candidate_count=0. Si V3 tiene candidatos pero
   necesita una aclaracion, no se contabiliza como busqueda sin resultados.
6. Se mantiene el filtrado fuerte de Analista, Auditor y Comentarista de v0.2.3.
7. La cobertura editorial incluye posts publish, future y draft para evitar
   proponer un duplicado mientras ya existe un borrador en preparacion.
8. Los H2/H3 solo conservan objetos respaldados por el titulo, Vocabulary o
   categorias. Si no, heredan el objeto principal o se descartan.
9. Se amplia el filtrado de palabras discursivas para evitar fingerprints como
   object=importa, object=tres, object=guia u object=decide.
10. Solucionador continua creando el post solo tras aprobacion y siempre como
    draft, con Vocabulary y relaciones post_to_category.

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
1. Copiar la carpeta includes/solucionador/ de esta version y, si se usa el
   paquete completo, sustituir includes/seo-includes-bootstrap.php.
2. Abrir Herramientas -> Solucionador.
3. Pulsar "Reanalizar fuentes". Es importante: v0.2.4 limpia automaticamente
   propuestas automaticas antiguas que ya no pasan los filtros nuevos.
4. Descargar el JSON y revisar:
   - propuestas;
   - titulo;
   - categorias;
   - Vocabulary;
   - coverage;
   - last_scan.accepted_by_source / discarded_by_source.
5. No aprobar un borrador hasta validar el primer JSON de v0.2.4.

Base de datos
-------------
No hay cambio de esquema respecto a v0.2.1. DB version sigue siendo 0.2.0.
