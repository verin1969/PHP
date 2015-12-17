# -*- coding: utf-8 -*-
import os, socket, sys, traceback, urllib2, urlparse, pycurl
try:
    from cStringIO import StringIO
except ImportError:
    from StringIO import StringIO
from simplejson.encoder import JSONEncoder
from simplejson.decoder import JSONDecoder


srv_cfg = {'host': 'admin.go.iptvportal.ru', 'username': 'admin', 'password': 'psw'}


def send (url, data, headers={}):
    method = 'POST'
    timeout = 300
    cacert = None
    c = pycurl.Curl ()
    c.setopt (pycurl.URL, str (url))
    buf = StringIO ()
    c.setopt (pycurl.WRITEFUNCTION, buf.write)
    #c.setopt(pycurl.READFUNCTION, read)
    #data = StringIO (data)
    #c.setopt(pycurl.HEADERFUNCTION, header)
    if cacert:
        c.setopt (c.CAINFO, (cacert)) 
    c.setopt (pycurl.SSL_VERIFYPEER, cacert and 1 or 0)
    c.setopt (pycurl.SSL_VERIFYHOST, cacert and 2 or 0)
    c.setopt (pycurl.ENCODING, 'gzip')
    c.setopt (pycurl.CONNECTTIMEOUT, 30)
    c.setopt (pycurl.TIMEOUT, timeout)
    if method == 'POST':
        c.setopt (pycurl.POST, 1)
        c.setopt (pycurl.POSTFIELDS, data)            
    if headers:
        hdrs = ['%s: %s' % (str(k), str(v)) for k, v in headers.items ()]
        c.setopt (pycurl.HTTPHEADER, hdrs)
    c.perform ()
    c.close ()
    return buf.getvalue ()

_id = 1
def get_id ():
    global _id
    id = _id
    _id += 1
    return id


def jsonrpc_call (uri, method, params, headers={}):
    if type (method) is list:
        methodlist = method
        jsonrpc = []
        for method, params in methodlist:
            jsonrpc.append ({
                'jsonrpc' : "2.0",
                'id'      : get_id (),
                'method'  : method,
                'params'  : params,
            })
    else:
        jsonrpc = {
            'jsonrpc' : "2.0",
            'id'      : get_id (),
            'method'  : method,
            'params'  : params,
        }
    res = send (uri, JSONEncoder (ensure_ascii=True).encode (jsonrpc), headers=headers)
    #print "res:", res
    if not res:
        print "error: not result"
        return None
    res = JSONDecoder (encoding='utf-8').decode (res)
    if type (res) is list:
        return res
    elif not res.get ('result'):
        print "error:" , res.get ('error')
        return False
    else:
        return res.get ('result')


class JsonSqlRpc (object):

    iptvportal_auth_header = None

    def __init__ (self, host=None, username=None, password=None):
        self.host = host
        self.username = username
        self.password = password
        self.jsonrpc_url = 'https://%s/api/jsonrpc/' % host
        self.jsonsql_url = 'https://%s/api/jsonsql/' % host

    def authorize_user (self, username=None, password=None):
        res = jsonrpc_call (self.jsonrpc_url, "authorize_user", {
            'username': username or self.username,
            'password': password or self.password,
        })
        #print "res:", res
        if res: self.iptvportal_auth_header = {'Iptvportal-Authorization': 'sessionid=' + res.get ('session_id')}
        return res

    def jsonsql_call (self, cmd, params=None):
        return jsonrpc_call (self.jsonsql_url, cmd, params, headers=self.iptvportal_auth_header)


srv = JsonSqlRpc (**srv_cfg)
srv_user = srv.authorize_user ()
#print 'authorize srv user:', srv_user


# выборка списка абонентов
res = srv.jsonsql_call ("select", {
    "data": ["username", "password"],
    "from": "subscriber"
})
print 'select cmd result:', res

# выборка списка тв медиа
res = srv.jsonsql_call ("select", {
    "data": ["name", {
        "concat": ["protocol", "://", "inet_addr",
                            {"coalesce": [{"concat": [":", "port"]}, ""]},
                            {"coalesce": [{"concat": ["/", "path"]}, ""]}
        ], "as": "mrl"}],
    "from": "media",
    "where": {"eq": ["is_tv", True]}
})
print 'select cmd result:', res

# выборка списка терминалов
res = srv.jsonsql_call ("select", {
    "data": [{"t": "inet_addr"}, {"t": "mac_addr"}, {"s": "username"}],
    "from": [{
        "table": "terminal", "as": "t"
    }, {
        "join": "subscriber", "join_type": "left", "as": "s", "on": {"eq": [{"t": "subscriber_id"}, {"s": "id"}]}
    }],
    "order_by": {"s": "username"}
})
print 'select cmd result:', res

# добавление абонента "123456" с паролем "111"
res = srv.jsonsql_call ("insert", {
    "into": "subscriber",
    "columns": ["username", "password"],
    "values": {
        "username": "123456",
        "password": "111",
    },
    "returning": "id"
})
print 'insert cmd result:', res

# добавление терминала с мак-адресом '11-22-33-44-55-66' абоненту "123456"
res = srv.jsonsql_call ("insert", {
    "into": "terminal",
    "columns": ["subscriber_id", "mac_addr", "registered"],
    "select": {
        "data": ["id", '11-22-33-44-55-77', True],
        "from": {
            "table": "subscriber", "as": "s"
        },
        "where": {
            "eq": ["username", "123456"]
        }
    },
    "returning": "id"
})
print 'insert cmd result:', res

# добавление пакетов "movie2", "sports2"
res = srv.jsonsql_call ("insert", {
    "into": "package",
    "columns": ["name", "paid"],
    "values": [["movie2", True], ["sports2", True]],
    "returning": "id"
})
print 'insert cmd result:', res

# добавление пакетов "movie", "sports" абоненту "123456"
res = srv.jsonsql_call ("insert", {
    "into": "subscriber_package",
    "columns": ["subscriber_id", "package_id", "enabled"],
    "select": {
        "data": [{"s": "id"}, {"p": "id"}, True],
        "from": [{
            "table": "subscriber", "as": "s"
        }, {
            "table": "package", "as": "p"
        }],
        "where": {
            "and": [{
                "eq": [{"s": "username"}, "123456"]
            }, {
                "in": [{"p": "name"}, "movie", "sports"]
            }]
        }
    },
    "returning": "package_id"
})
print 'insert cmd result:', res

# отключение абонента с акаунтом "123456"
res = srv.jsonsql_call ("update", {
    "table": "subscriber",
    "set"  : {
        #"disabled": {'not': {'subscriber': "disabled"}}
        "disabled": {'not': "disabled"}
    },
    "where": {"eq": ["username", "123456"]},
    "returning": "id"
})
print 'update cmd result:', res

# удаление абонентских устройств акаунта "123456"
res = srv.jsonsql_call ("delete", {
    "from": "terminal",
    "where": {"in": ["subscriber_id", {
        "select": {
            "data": "id",
            "from": "subscriber",
            "where": {"eq": ["username", "123456"]}
        }
    }]},
    "returning": "id"
})
print 'delete cmd result:', res

# удаление пакетов для акаунта "123456"
res = srv.jsonsql_call ("delete", {
    "from": "subscriber_package",
    "where": {"in": ["subscriber_id", {
        "select": {
            "data": "id",
            "from": "subscriber",
            "where": {"eq": ["username", "123456"]}
        }
    }]},
    "returning": "package_id"
})
print 'delete cmd result:', res

# список запросов
res = srv.jsonsql_call ([(
    "select", {
    "data": ["name", {
        "concat": ["protocol", "://", "inet_addr",
                            {"coalesce": [{"concat": [":", "port"]}, ""]},
                            {"coalesce": [{"concat": ["/", "path"]}, ""]}
        ], "as": "mrl"}],
    "from": "media",
    "where": {
        "and": [{
            "eq": ["is_tv", True]
        }, {
            "eq": ["inet_addr", "235.10.10.1"]
        }]
    }}), (
    "select", {
    "data": ["name", {
        "concat": ["protocol", "://", "inet_addr",
                            {"coalesce": [{"concat": [":", "port"]}, ""]},
                            {"coalesce": [{"concat": ["/", "path"]}, ""]}
        ], "as": "mrl"}],
    "from": "media",
    "where": {
        "and": [{
            "eq": ["is_tv", True]
        }, {
            "eq": ["inet_addr", "235.10.10.2"]
        }]
    }}), (
    "select", {
    "data": ["name", {
        "concat": ["protocol", "://", "inet_addr",
                            {"coalesce": [{"concat": [":", "port"]}, ""]},
                            {"coalesce": [{"concat": ["/", "path"]}, ""]}
        ], "as": "mrl"}],
    "from": "media",
    "where": {
        "and": [{
            "eq": ["is_tv", True]
        }, {
            "eq": ["inet_addr", "235.10.10.3"]
        }]
    }})
], None)
print 'select cmd result:', res


# выборка списка значений
res = srv.jsonsql_call ("select", {
    "values": [[1, 'a'], [2, 'b'], [3, 'c']],
})
print 'select cmd result:', res

# выборка списка значений
res = srv.jsonsql_call ("select", {
    "data": ["username", "password"],
    "from": "subscriber",
    "where": {
        "in": ["username", {"values": [['12345'], ['123456']]}]
    }
})
print 'select cmd result:', res
