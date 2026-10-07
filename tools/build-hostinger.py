"""
Monta o pacote para a Hostinger (example.com/conteudos).

O zip tem duas pastas e deve ser extraído em domains/example.com/:

  public_html/conteudos/   só o que o navegador acessa (index.php, CSS, JS, ícones)
  conteudos-app/           o sistema: código, banco, chave do Google, cópias de vídeo
                           (fora do public_html, ninguém baixa pela internet)

Uso:
  python tools/build-hostinger.py --composer "php caminho/composer.phar"            # primeira instalação
  python tools/build-hostinger.py --composer "php caminho/composer.phar" --update   # atualização:
      não leva banco, .env nem chave do Google, para não apagar o que já está no servidor.
"""

import argparse
import base64
import os
import secrets
import shutil
import subprocess
import sys
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'deploy'
STAGE = OUT / 'stage'
APP = STAGE / 'conteudos-app'
WEB = STAGE / 'public_html' / 'conteudos'

APP_URL = 'https://example.com/conteudos'

# Pastas/arquivos do projeto que não vão para o servidor.
SKIP_DIRS = {'node_modules', 'vendor', '.git', 'tests', '.claude', 'deploy', 'tools', '.idea', '.vscode'}
SKIP_FILES = {'.env', 'phpunit.xml', '.npmrc', 'package.json', 'package-lock.json', 'vite.config.js',
              'AGENTS.md', 'CLAUDE.md', '.editorconfig', '.gitattributes', 'hot'}
SKIP_PATHS = {
    'storage/app/private/media', 'storage/app/private/reviews', 'storage/logs',
    'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views',
    'storage/framework/testing',
}

# O WordPress do domínio usa LiteSpeed Cache, que guarda até páginas de erro.
# Dentro de /conteudos ele não deve servir nada guardado.
LITESPEED_BLOCK = """# O WordPress do domínio usa LiteSpeed Cache: não servir páginas dele guardadas aqui.
<IfModule LiteSpeed>
    CacheLookup off
</IfModule>

"""

WEB_INDEX = """<?php

use Illuminate\\Foundation\\Application;
use Illuminate\\Http\\Request;

define('LARAVEL_START', microtime(true));

// O sistema fica fora do public_html (domains/example.com/conteudos-app).
$appRoot = __DIR__.'/../../conteudos-app';

if (file_exists($maintenance = $appRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// O File Browser da Hostinger não extrai arquivos que começam com ponto.
// Sem este .htaccess, só a página inicial abre (o resto cai no WordPress),
// então o index.php recria o arquivo sozinho se ele sumir.
if (! file_exists(__DIR__.'/.htaccess')) {
    @file_put_contents(__DIR__.'/.htaccess', <<<'HTACCESS'
# O WordPress do domínio usa LiteSpeed Cache: não servir páginas dele guardadas aqui.
<IfModule LiteSpeed>
    CacheLookup off
</IfModule>

<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
HTACCESS);
}

require $appRoot.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appRoot.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
"""

ENV = """APP_NAME="Estúdio de Conteúdo"
APP_ENV=production
APP_KEY={key}
APP_DEBUG=false
APP_URL={url}
APP_TIMEZONE=America/Sao_Paulo
APP_LOCALE=pt_BR
APP_FALLBACK_LOCALE=pt_BR
APP_FAKER_LOCALE=pt_BR
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=sqlite

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/conteudos
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=log

GOOGLE_SERVICE_ACCOUNT_JSON=storage/app/private/google/service-account.json
"""


def run(cmd, cwd, env=None):
    print('$', cmd)
    subprocess.run(cmd, cwd=cwd, shell=True, check=True, env={**os.environ, **(env or {})})


def copy_project():
    for src in ROOT.rglob('*'):
        rel = src.relative_to(ROOT)
        rel_posix = rel.as_posix()
        if rel.parts[0] in SKIP_DIRS or src.name in SKIP_FILES:
            continue
        if any(rel_posix == p or rel_posix.startswith(p + '/') for p in SKIP_PATHS):
            continue
        if rel_posix.startswith('database/database.sqlite'):
            continue
        dest = APP / rel
        if src.is_dir():
            dest.mkdir(parents=True, exist_ok=True)
        else:
            dest.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, dest)

    # Pastas que o Laravel precisa poder escrever.
    for d in ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions',
              'storage/framework/views', 'storage/app/private/media', 'storage/app/private/reviews', 'bootstrap/cache']:
        (APP / d).mkdir(parents=True, exist_ok=True)
        keep = APP / d / '.gitignore'
        if not keep.exists():
            keep.write_text('*\n!.gitignore\n')


def split_public():
    WEB.mkdir(parents=True, exist_ok=True)
    for item in (APP / 'public').iterdir():
        shutil.move(str(item), WEB / item.name)
    shutil.rmtree(APP / 'public')
    (WEB / 'index.php').write_text(WEB_INDEX, encoding='utf-8')
    htaccess = WEB / '.htaccess'
    htaccess.write_text(LITESPEED_BLOCK + htaccess.read_text(encoding='utf-8'), encoding='utf-8')
    (WEB / 'hot').unlink(missing_ok=True)


def fresh_database(composer_env):
    db = APP / 'database' / 'database.sqlite'
    db.touch()
    artisan = 'php artisan'
    run(f'{artisan} migrate --force', APP, composer_env)
    run(f'{artisan} db:seed --force', APP, composer_env)
    # Pastas monitoradas: o que já existe nelas hoje não vira conteúdo.
    run(f'{artisan} drive:watch <ID_DA_PASTA_1> --account=principal', APP, composer_env)
    run(f'{artisan} drive:watch <ID_DA_PASTA_2> --account=principal', APP, composer_env)
    run(f'{artisan} drive:watch <ID_DA_PASTA_3> --account=podcast', APP, composer_env)

    links = []
    for name in ['Ana', 'Eduardo']:
        out = subprocess.run(f'{artisan} users:link "{name}"', cwd=APP, shell=True, check=True,
                             capture_output=True, text=True, env={**os.environ, **composer_env}).stdout
        links.append((name, out.strip().splitlines()[-1]))
    return links


def make_zip(name):
    target = OUT / name
    target.unlink(missing_ok=True)
    with zipfile.ZipFile(target, 'w', zipfile.ZIP_DEFLATED, compresslevel=6) as z:
        for path in sorted(STAGE.rglob('*')):
            arc = path.relative_to(STAGE).as_posix()
            if path.is_dir():
                z.writestr(arc + '/', '')
            else:
                z.write(path, arc)
    return target


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--composer', default='composer')
    parser.add_argument('--update', action='store_true')
    args = parser.parse_args()

    shutil.rmtree(STAGE, ignore_errors=True)
    STAGE.mkdir(parents=True)

    run('npm run build', ROOT)
    copy_project()
    run(f'{args.composer} install --no-dev --optimize-autoloader --no-interaction --no-progress', APP)

    # Sem WAL durante a montagem: o banco precisa ficar todo no arquivo .sqlite.
    env = {'DB_JOURNAL_MODE': 'delete'}

    links = []
    if args.update:
        shutil.rmtree(APP / 'storage' / 'app' / 'private' / 'google', ignore_errors=True)
    else:
        key = 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode()
        (APP / '.env').write_text(ENV.format(key=key, url=APP_URL), encoding='utf-8')
        links = fresh_database(env)

    # Nada de cache/log da montagem vai junto.
    for d in ['storage/logs', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views']:
        for f in (APP / d).iterdir():
            if f.name != '.gitignore':
                shutil.rmtree(f) if f.is_dir() else f.unlink()

    split_public()
    zip_path = make_zip('conteudos-ec-atualizacao.zip' if args.update else 'conteudos-ec-hostinger.zip')
    shutil.rmtree(STAGE, ignore_errors=True)

    if links:
        lines = ['Links de acesso (pessoais, não compartilhe):', '']
        lines += [f'{name}: {url}' for name, url in links]
        (OUT / 'ACESSOS.txt').write_text('\n'.join(lines) + '\n', encoding='utf-8')

    size = zip_path.stat().st_size / 1024 / 1024
    print(f'\nPronto: {zip_path} ({size:.1f} MB)')


if __name__ == '__main__':
    sys.exit(main())
