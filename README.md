# php-server

通过 PHP 控制进程，实现一个简易的 Server。

## 控制流程

![控制流程](https://img0.fanhaobai.com/2018/09/process-php-multiprocess-server/e0e86073-3093-4e5f-be20-b64510e61575.png)

## 命令

该 PHPServer 仅实现了`start|stop|reload|help`命令。

```Bash
$ php server.php --help
Usage: Commands [mode]

Commands:
start		Start worker.
stop		Stop worker.
reload		Reload codes.

Options:
-d		to start in DAEMON mode.

Use "--help" for more information about a command.
```

### start

```Bash
$ php server.php start -d
PHPServer start	  [OK]

$ pstree -p
init(1)-+-init(3)---bash(5)
        |-php(10525)-+-php(10526)
        |            |-php(10527)
        |            |-php(10528)
        |            |-php(10529)
        |            |-php(10530)
        |            |-php(10531)
        |            |-php(10532)
        |            |-php(10533)
        |            |-php(10534)
        |            `-php(10535)
```

### stop

```Bash
$ php server.php stop
PHPServer stopping ...
PHPServer stop success
```

### reload

`reload`只会重载 worker 进程，也就是说`reload`时 master 进程 PID 并不会变化。

```Bash
$ pstree -p
init(1)-+-init(3)---bash(5)
        |-php(10525)-+-php(10526)
        |            |-php(10527)
        |            |-php(10528)
        |            |-php(10529)
        |            |-php(10530)
        |            |-php(10531)
        |            |-php(10532)
        |            |-php(10533)
        |            |-php(10534)
        |            `-php(10535)

$ php server.php reload
PHPServer reloading ...

$ pstree -p
init(1)-+-init(3)---bash(5)
        |-php(10525)-+-php(10538)
        |            |-php(10539)
        |            |-php(10540)
        |            |-php(10541)
        |            |-php(10542)
        |            |-php(10543)
        |            |-php(10544)
        |            |-php(10545)
        |            |-php(10546)
        |            `-php(10547)
```
