<?php

/*
|--------------------------------------------------------------------------
| Regras do catálogo de conteúdos
|--------------------------------------------------------------------------
|
| Como a sincronização com o Drive decide o que vira conteúdo, o que é
| ignorado e quais campos já chegam preenchidos. Todas as expressões são
| testadas sem diferenciar maiúsculas/minúsculas contra os nomes reais
| das pastas e arquivos (com acentos).
|
*/

return [

    // Arquivos que viram conteúdo. Todo o resto (Docs, planilhas, áudio,
    // projetos do Premiere...) é ignorado.
    'mime_prefixes' => ['image/', 'video/'],

    'ignore_extensions' => [
        'arw', 'cr2', 'cr3', 'nef', 'dng', 'raw',       // fotos brutas de câmera
        'prproj', 'prin', 'aep', 'psd', 'ai', 'indd',   // arquivos de projeto
        'mp3', 'wav', 'm4a',                            // áudio do podcast
    ],

    // Se QUALQUER pasta no caminho (abaixo da pasta monitorada) bater com
    // uma destas, o arquivo é ignorado.
    'ignore_folder_patterns' => [
        '/\bbruto\b/iu',
        '/\bcaptad[oa]s?\b/iu',
        '/\bdescartad[oa]s\b/iu',
        '/\bpostad[oa]s\b/iu',
        '/\bpublicad[oa]s\b/iu',
        '/\bvers[ãa]o antiga\b/iu',
        '/\barquivos do pc\b/iu',
        '/\bbanners e tags\b/iu',
        '/^(fotos\b|melhores fotos)/iu',
        '/\btestes?\b/iu',
        '/\bcl[íi]nica parceira\b/iu',   // a clínica posta o próprio Instagram
        '/\bcortes crus\b/iu',
    ],

    'ignore_file_patterns' => [
        '/\bthumb\b/iu',            // thumbnail do YouTube
        '/vers[ãa]o\s*yt\b/iu',
        '/^dsc\d+/iu',              // foto direto da câmera
        '/^captura de tela/iu',
    ],

    // Imagens dentro de uma pasta cujo caminho bate com isto (ou cujo nome
    // de arquivo bate com o segundo padrão) viram UM carrossel por pasta:
    // cada subpasta = um post.
    'carousel_folder_pattern' => '/carross|capas? de revista/iu',
    'carousel_file_pattern' => '/carross|caparevista/iu',

    // CTA sugerido pelo nome do arquivo ou da pasta.
    'cta_patterns' => [
        'trafego' => '/tr[áa]fego/iu',
        'organico' => '/org[âa]nico|_org\b/iu',
    ],

    // Conta sugerida pelo caminho (pastas). A primeira regra que bater vence;
    // se nenhuma bater, usa a conta padrão da pasta monitorada.
    'account_rules' => [
        ['pattern' => '/est[áa]ticos.*\/marca b/iu', 'account' => 'marca_b'],
    ],

    // Quem produziu, pelo nome da pasta. O primeiro grupo capturado vira o
    // nome; se não houver grupo, usa 'name'.
    'producer_folder_rules' => [
        ['pattern' => '/videomaker\s*\d+\s*-\s*(.+)$/iu'],
        ['pattern' => '/est[úu]dio parceiro/iu', 'name' => 'Estúdio Parceiro'],
    ],

    // Se nenhuma pasta indicar quem produziu, usa o dono do arquivo no Drive.
    // E-mail => nome curto para mostrar no sistema.
    // null = conta compartilhada, não indica quem produziu (Ana escolhe).
    'people' => [
        'ana@example.com' => 'Ana',
        'social@example.com' => 'Ana',
        'paulo@example.com' => 'Paulo',
        'felipe@example.com' => 'Felipe',
        'renato@example.com' => 'Renato',
        'clinica@example.com' => null,
    ],

    // Pastas estruturais que não servem como "projeto/assunto".
    'project_skip_patterns' => [
        '/^\d{4}$/u',
        '/^(janeiro|fevereiro|mar[çc]o|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)$/iu',
        '/videomaker/iu',
        '/est[úu]dio parceiro/iu',
        '/^carross/iu',
        '/^cortes reels$/iu',
        '/teaser/iu',
        '/temporada/iu',
        '/para confer[êe]ncia/iu',
    ],

    // Correção que chega como arquivo novo: se o nome for parecido com o de
    // um conteúdo aguardando correção na mesma pasta, o sistema sugere o vínculo.
    'correction_name_similarity' => 70,

    // Cópias de vídeo/imagem guardadas no servidor para a prévia.
    'media_disk' => 'local',
    'media_dir' => 'media',
    'purge_media_after_days' => 7,

];
