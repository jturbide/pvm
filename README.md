# PVM (PHP Version Manager) for Windows

Because who needs Docker or WSL when you can do everything *the hard way*?

Because we love adventure and micro-optimizations on Windows machines!

## Introduction

**PVM** is yet another attempt at creating a **PHP Version Manager** for Windows. Yes, the land where you can run EXEs as Administrator all day, fix your registry keys, and now also manage multiple PHP installations and their PECL extensions – directly from your Windows CLI!

> *“But why not just use Docker or WSL?”*  
> Because obviously it’s more fun to spend hours tinkering with environment variables, `.ini` merges, and rummaging around in your `AppData\Local\Temp` folder. Also, you might gain a 0.0001% performance boost by running native Windows builds. *Totally worth it!*

## Main Features

1. **Multiple PHP Versions**
    - Install `php84` or `php82` or any other `phpXX`.
    - In-place **upgrade** to new patch releases (like going from 8.4.4 to 8.4.5) while keeping your precious `php.ini` intact.
    - *“Wait, do we handle merges?”* – Nah, we just skip overwriting your existing config. If something breaks, well… it’s Windows, you’re used to it.

2. **PECL Extensions**
    - Because we can’t have it easy, we also let you `pvm install php82-phalcon5.8.0` and parse the labyrinth of `https://downloads.php.net/~windows/pecl/releases/` to find the right DLL.
    - Automatic addition of `extension="phalcon.dll"` to your `php.ini`. If that line doesn’t work, hey, at least we tried.

3. **Search Command**
   - `pvm search redis --ext-version=5.3 --php-version=8 --nts-only`
   - Lists all matching extensions (by partial name), partial extension version (e.g. `5.3` → matches `5.3.7`, etc.), partial PHP version (e.g. `8` → matches 8.0, 8.1, 8.2), architecture (x64 or x86), thread safety, etc.
   - Great for discovering which DLLs are out there for your Windows environment.

4. **Uninstall** (with optional `--force`)
    - Remove entire directories in a single command. Because who needs backups or regrets?

5. **Ties in nicely** with your existing Windows stack – or so we claim.
    - Optionally tweak your Apache config. Or your Nginx config. Or your *IIS config* if you’re feeling extra adventurous.
    - Did we test that? Probably not.

6. **Humor**
    - There’s a built-in requirement to appreciate puns and ironically complicated solutions. If you can’t handle that, there’s always Docker or WSL.

## Why PVM?

- **The thrill** of discovering yet another .exe or .dll in your path.
- **Stop** wasting time on container solutions that “just work.” Instead, get your hands dirty with manual merges, environment paths, and random errors about missing `VCRUNTIME140.dll`.
- **You love** the nostalgic feeling of rummaging around in `.zip` files, copying them over existing folders, and crossing your fingers that your system doesn’t blow up.

## Installation

1. **Clone** this repository (or download the .zip, we’re big fans of .zips!).
2. **Run** `composer install` (because ironically we do use Composer).
3. **Check** that your `bin\pvm` (or `pvm.bat`) is somewhere in your PATH.
4. **Optionally** create `config\config.json` and `config\cache.json` as `{}` to avoid suspicious errors.
5. **Profit** – or at least watch it do *something*.

## Usage Examples

**Install a base PHP version**  
```
pvm install php84
```

Downloads the latest patch from windows.php.net/downloads/releases/, extracts it to `packages\php84`, and copies `php.ini-production` → `php.ini`.

**Upgrade that base version**  
```
pvm install php84
```

If it’s already installed and a new patch is out, we ask if you want to upgrade. Because yes, we multi-purpose the “install” command. In-place overwrite, skipping your beloved `php.ini`.

**Install a PECL extension**  
```
pvm install php82-phalcon5.8.0
```

Because you love living on the edge, we parse downloads.php.net/~windows/pecl/ to rummage for an `x64 NTS vs17` .zip or .dll, then politely chuck it into `ext\` and add a line to `php.ini`. If something breaks? You’ll find out soon enough when `phpinfo()` bursts into flames.

**Search a PECL extension**  
```
pvm search redis --ext-version=5.3 --php-version=8 --nts-only
```

Lists all `redis` extension builds that have a version containing `5.3`, PHP version containing `8`, and are NTS builds.

**Uninstall**  
```
pvm uninstall php84  
pvm uninstall php82-phalcon5.8.0
```

Because we believe in the power of the `rm -rf` approach (or in Windows terms, forcibly removing directories). Freed from your HPC-like environment, you can re-download or jump to another version. Because YOLO.

## Disclaimers & Warnings

- **No merges**: We don’t do fancy merges with `php.ini`. We skip overwriting it. If upstream drastically changed config items, you’ll find out the fun way.
- **No official support** for random DLL edge cases. If you can’t find a Windows build of that obscure PECL extension, tough luck. You might have to compile from source or revert to your cozy WSL.
- **We disclaim** responsibility for accidental system meltdown, infinite loops, or the dreaded “File in use” errors because Windows locked a file. You got yourself into this.

## Future Plans

- Possibly do a `pvm upgrade --all` that tries to upgrade every base package **and** every extension in one go. Because that definitely won’t break.
- Add an **interactive** “Are you sure? Y/N” for everything, because user prompts are fun.
- Possibly integrate with **IIS**. Don’t worry, if you do it, you’re truly unstoppable.

## Conclusion

**PVM**: A comedic approach to doing everything Docker solves in a couple of lines – but natively on Windows, for that “old-school sysadmin” vibe. You’ll learn a lot about environment variables, `.zip` extractions, and the wonderful ways of Windows path resolution. Are you in?
