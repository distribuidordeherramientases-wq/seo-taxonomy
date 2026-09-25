# Procesos y workers

SEO Taxonomy utiliza un gestor central de procesos para tareas largas.

Coordina estado, arranque/parada, velocidad, ventanas de ejecución, locks, reanudación y supervisión.

Puede gestionar procesos como Import / Export, Clonador, Academia, Lingüista y Ojeador.

No deben ejecutarse simultáneamente procesos incompatibles que escriban sobre las mismas capas.
