SOLUCIONADOR v0.3.0
===================

Objetivo
--------
Ser la capa unica de decision editorial del plugin.

Solucionador no sustituye a los servicios especialistas. Recoge sus conclusiones,
las cruza con la cobertura editorial existente, prioriza que merece actuacion y
mantiene en un unico sitio el diagnostico, la decision y el estado de cada
propuesta.

Fuentes
-------
- Dependiente / Interprete: preguntas reales, intencion, contexto, resultados y feedback.
- Analista: trafico, demanda, rendimiento y prioridades ya calculadas.
- Auditor: carencias, cobertura, calidad y hallazgos.
- Ojeador: conclusiones de mercado y oportunidad ya calculadas.
- Ingeniero: conocimiento tecnico aprobado por categoria.
- Clasificador: estructura semantica y conceptos nuevos/equivalentes pendientes.
- Comentarista: preguntas/problemas observados en fuentes externas.
- Entradas, Paginas y Categorias: inventario, rendimiento y cobertura existentes.

Limites de responsabilidad
--------------------------
Solucionador no:
- aprende el catalogo;
- sustituye a Interprete;
- investiga documentacion tecnica;
- consulta Google Shopping por su cuenta;
- crea otra medicion de trafico;
- redacta ni publica automaticamente.

Salida
------
Cada decision puede producir:
- mejorar contenido existente;
- crear post;
- crear landing;
- anadir una seccion;
- observar;
- no hacer nada.

El brief editorial puede incluir:
- tema e intencion;
- salida recomendada;
- fuentes y evidencias;
- categorias y productos relacionados;
- Vocabulary;
- conceptos que faltan;
- conocimiento tecnico de Ingeniero;
- contenido existente que debe conservarse;
- enlaces internos;
- seguimiento disponible.

Centralizacion de interfaz
--------------------------
El analisis editorial visible se concentra en:
SEO Taxonomy -> Contenidos -> Solucionador -> Diagnostico editorial

Entradas, Paginas y Categorias quedan como lugares de edicion/ejecucion y
fuentes de datos. Los informes historicos reutilizan sus motores existentes
pero dejan de presentarse como sistemas paralelos visibles.

Pestanas
--------
- Resumen
- Diagnostico editorial
- Propuestas editoriales
- Cobertura editorial
- Fuentes y servicios
- Datos internos

Posts
-----
La aprobacion explicita de una propuesta create_post sigue creando solamente
un borrador con categorias product_cat y Vocabulary canonico. No publica.

Landings
--------
Las candidatas existentes del motor de landings se muestran tambien en
Propuestas editoriales. Solucionador centraliza la decision; Paginas conserva
la ejecucion final.

Base de datos
-------------
No hay cambio de esquema en esta version. Se conservan las tablas y datos
existentes para compatibilidad.
