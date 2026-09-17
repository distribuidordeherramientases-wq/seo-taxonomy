<?php

defined('ABSPATH') || exit;

/*
 * Gramática castellana general para el Intérprete.
 *
 * No contiene el catálogo ni respuestas de producto. Su función es reconocer
 * ruido gramatical, formas verbales frecuentes e irregularidades del idioma.
 * El vocabulario específico de herramientas se aprende del propio catálogo.
 */
return array(
    'stopword_groups' => array(
        'article' => array(
            'el','la','los','las','lo','un','una','unos','unas','al','del',
        ),
        'preposition' => array(
            'a','ante','bajo','cabe','con','contra','de','desde','durante','en','entre',
            'hacia','hasta','mediante','para','por','segun','sin','so','sobre','tras','via',
        ),
        'determiner' => array(
            'mi','mis','tu','tus','su','sus','nuestro','nuestra','nuestros','nuestras',
            'vuestro','vuestra','vuestros','vuestras','este','esta','estos','estas','ese',
            'esa','esos','esas','aquel','aquella','aquellos','aquellas','alguno','alguna',
            'algunos','algunas','ningun','ninguna','ningunos','ningunas','cada','todo','toda',
            'todos','todas','otro','otra','otros','otras','mismo','misma','mismos','mismas',
        ),
        'pronoun' => array(
            'yo','tu','el','ella','ello','nosotros','nosotras','vosotros','vosotras','ellos',
            'ellas','usted','ustedes','me','te','se','nos','os','le','les','lo','la','los','las',
            'mi','ti','si','conmigo','contigo','consigo','quien','quienes','cual','cuales',
        ),
        'conjunction' => array(
            'y','e','ni','o','u','pero','mas','sino','aunque','porque','pues','mientras',
            'si','que','como','cuando','donde','ya','sea',
        ),
        'interrogative' => array(
            'que','cual','cuales','quien','quienes','cuanto','cuanta','cuantos','cuantas',
            'donde','como','cuando','porqué','porque',
        ),
    ),

    /*
     * Palabras que pueden retirarse de la consulta semántica. Se excluyen a
     * propósito no/sin/con/contra/hasta/entre porque pueden cambiar el requisito.
     */
    'semantic_stopwords' => array(
        'el','la','los','las','lo','un','una','unos','unas','al','del',
        'a','ante','bajo','cabe','de','desde','durante','en','hacia','mediante','para','por','segun','so','sobre','tras','via',
        'mi','mis','tu','tus','su','sus','nuestro','nuestra','nuestros','nuestras','vuestro','vuestra','vuestros','vuestras',
        'este','esta','estos','estas','ese','esa','esos','esas','aquel','aquella','aquellos','aquellas',
        'alguno','alguna','algunos','algunas','ningun','ninguna','ningunos','ningunas','cada','todo','toda','todos','todas',
        'otro','otra','otros','otras','mismo','misma','mismos','mismas',
        'yo','tu','el','ella','ello','nosotros','nosotras','vosotros','vosotras','ellos','ellas','usted','ustedes',
        'me','te','se','nos','os','le','les','mi','ti','si','conmigo','contigo','consigo',
        'y','e','ni','o','u','pero','sino','aunque','porque','pues','mientras','que','como','cuando','donde',
        'cual','cuales','quien','quienes','cuanto','cuanta','cuantos','cuantas',
    ),

    'stop_verbs' => array(
        'ser','estar','haber','tener','hacer','poder','querer','necesitar','buscar',
        'comprar','elegir','usar','utilizar','servir','permitir','ofrecer','incluir',
        'contar','trabajar','llevar','venir','deber','soler','poner','dar','ver','saber',
        'decir','ir','parecer','gustar','preferir','encontrar','mirar',
    ),

    'filler_phrases' => array(
        'quiero', 'necesito', 'estoy buscando', 'busco', 'me hace falta',
        'me gustaria', 'quisiera', 'a ver si teneis', 'a ver si tienes',
        'que necesito para', 'que puedo usar para', 'que herramienta necesito para',
        'me puedes decir', 'podrias decirme', 'me vendria bien', 'estoy intentando',
    ),

    /*
     * Formas irregulares y cambios de raíz frecuentes. Las formas regulares se
     * compilan automáticamente desde los infinitivos que Lingüista aprende del
     * catálogo, de modo que esta lista no necesita enumerar todos los verbos.
     */
    'irregular_forms' => array(
        // ser
        'soy'=>'ser','eres'=>'ser','es'=>'ser','somos'=>'ser','sois'=>'ser','son'=>'ser',
        'era'=>'ser','eras'=>'ser','eramos'=>'ser','erais'=>'ser','eran'=>'ser',
        'sea'=>'ser','seas'=>'ser','seamos'=>'ser','seais'=>'ser','sean'=>'ser','sido'=>'ser','siendo'=>'ser',
        // estar
        'estoy'=>'estar','estas'=>'estar','esta'=>'estar','estamos'=>'estar','estais'=>'estar','estan'=>'estar',
        'estuve'=>'estar','estuviste'=>'estar','estuvo'=>'estar','estuvimos'=>'estar','estuvisteis'=>'estar','estuvieron'=>'estar',
        // haber
        'he'=>'haber','has'=>'haber','ha'=>'haber','hemos'=>'haber','habeis'=>'haber','han'=>'haber','hay'=>'haber',
        'habia'=>'haber','hubo'=>'haber','hubieron'=>'haber','haya'=>'haber','hayan'=>'haber','habido'=>'haber',
        // tener
        'tengo'=>'tener','tienes'=>'tener','tiene'=>'tener','tenemos'=>'tener','teneis'=>'tener','tienen'=>'tener',
        'tuve'=>'tener','tuviste'=>'tener','tuvo'=>'tener','tuvieron'=>'tener','tenga'=>'tener','tengan'=>'tener','tenido'=>'tener',
        // hacer
        'hago'=>'hacer','haces'=>'hacer','hace'=>'hacer','hacemos'=>'hacer','hacen'=>'hacer','hice'=>'hacer','hiciste'=>'hacer',
        'hizo'=>'hacer','hicimos'=>'hacer','hicieron'=>'hacer','haga'=>'hacer','hagan'=>'hacer','hecho'=>'hacer','haciendo'=>'hacer',
        // poder
        'puedo'=>'poder','puedes'=>'poder','puede'=>'poder','podemos'=>'poder','podeis'=>'poder','pueden'=>'poder',
        'pude'=>'poder','pudiste'=>'poder','pudo'=>'poder','pudieron'=>'poder','pueda'=>'poder','puedan'=>'poder',
        // querer
        'quiero'=>'querer','quieres'=>'querer','quiere'=>'querer','queremos'=>'querer','quereis'=>'querer','quieren'=>'querer',
        'quise'=>'querer','quisiste'=>'querer','quiso'=>'querer','quisieron'=>'querer','quiera'=>'querer','quieran'=>'querer',
        // ir
        'voy'=>'ir','vas'=>'ir','va'=>'ir','vamos'=>'ir','vais'=>'ir','van'=>'ir','iba'=>'ir','ibas'=>'ir','ibamos'=>'ir','iban'=>'ir',
        'vaya'=>'ir','vayan'=>'ir','ido'=>'ir','yendo'=>'ir',
        // poner
        'pongo'=>'poner','pones'=>'poner','pone'=>'poner','ponen'=>'poner','puse'=>'poner','pusiste'=>'poner','puso'=>'poner','pusieron'=>'poner','puesto'=>'poner',
        // decir
        'digo'=>'decir','dices'=>'decir','dice'=>'decir','dicen'=>'decir','dije'=>'decir','dijiste'=>'decir','dijo'=>'decir','dijeron'=>'decir','dicho'=>'decir','diciendo'=>'decir',
        // venir
        'vengo'=>'venir','vienes'=>'venir','viene'=>'venir','vienen'=>'venir','vine'=>'venir','viniste'=>'venir','vino'=>'venir','vinieron'=>'venir','viniendo'=>'venir',
        // dar / ver / saber
        'doy'=>'dar','di'=>'dar','dio'=>'dar','dieron'=>'dar','dado'=>'dar',
        'veo'=>'ver','ves'=>'ver','ve'=>'ver','vemos'=>'ver','ven'=>'ver','vi'=>'ver','vio'=>'ver','vieron'=>'ver','visto'=>'ver',
        'sabes'=>'saber','sabe'=>'saber','sabemos'=>'saber','saben'=>'saber','supe'=>'saber','supo'=>'saber','supieron'=>'saber',
        // extraer (acción muy relevante para el catálogo)
        'extraigo'=>'extraer','extraes'=>'extraer','extrae'=>'extraer','extraemos'=>'extraer','extraen'=>'extraer',
        'extraje'=>'extraer','extrajiste'=>'extraer','extrajo'=>'extraer','extrajeron'=>'extraer','extrayendo'=>'extraer','extraido'=>'extraer',
        // medir
        'mido'=>'medir','mides'=>'medir','mide'=>'medir','medimos'=>'medir','miden'=>'medir','midi'=>'medir','midio'=>'medir','midieron'=>'medir','midiendo'=>'medir','medido'=>'medir',
        // apretar
        'aprieto'=>'apretar','aprietas'=>'apretar','aprieta'=>'apretar','apretamos'=>'apretar','aprietan'=>'apretar',
        // soldar
        'sueldo'=>'soldar','sueldas'=>'soldar','suelda'=>'soldar','soldamos'=>'soldar','sueldan'=>'soldar',
        // serrar
        'sierro'=>'serrar','sierras'=>'serrar','sierra'=>'serrar','serramos'=>'serrar','sierran'=>'serrar',
        // mover
        'muevo'=>'mover','mueves'=>'mover','mueve'=>'mover','movemos'=>'mover','mueven'=>'mover',
        // pedir / servir
        'pido'=>'pedir','pides'=>'pedir','pide'=>'pedir','pedimos'=>'pedir','piden'=>'pedir',
        'sirvo'=>'servir','sirves'=>'servir','sirve'=>'servir','servimos'=>'servir','sirven'=>'servir',
        // sacar (cambio ortográfico en pretérito/subjuntivo)
        'saque'=>'sacar','saques'=>'sacar','saquemos'=>'sacar',
    ),

    'intents' => array(
        'compare' => array('comparar','compara','diferencia','diferencias','versus','vs','mejor que'),
        'compatibility' => array('compatible','compatibilidad','sirve para','encaja con','vale para','funciona con'),
        'replacement' => array('repuesto','recambio','sustituir','sustitucion','reemplazar','reemplazo'),
        'accessory' => array('accesorio','complemento','adaptador','adaptar'),
        'solve_problem' => array('tengo que','necesito','quiero','como puedo','que uso para'),
        'find_product' => array('busco','estoy buscando','comprar','quiero un','quiero una','necesito un','necesito una'),
    ),
);
