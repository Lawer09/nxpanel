




一、用户上报格式
- 请求体：  
  - reports：数组，可选，1–100 条 （可为空数组[]，或不传递）
    - node_id：可选，整数
    - node_host: 可选，字符, node_id 或 node_host 其中两者必有一个，优先选择node_id（或通过node_host查询节点id信息，如果找不到则node_id为0）
    - delay：必填，整数，0–60000（ms），当前节点的延迟
    - success_rate：必填，整数，0–100， 节点的成功率（由于目前每次连接都会上传，因此实际上这个要么时0要么时100）
    - status：探测状态，字符，如下为可选值
      - success
      - failed
      - timeout
      - cancelled
    - probe_stage：探测阶段（探测进行到的某一阶段，自上而下），字符
      - node_connect（tunnel_establish）：和节点连接的过程，其中可能传递tunnel_establish,归为node_connect
      - post_connect_probe：连上后再访问一个验证地址，例如 generate_204
    - error_code：错误码，字符串
  - user_default: 数组，item格式如下
    - type: 自定义用户数据格式
    - data: JSON 字符串
  - metadata字段 必填：
    - app_id:string（app包名）必填
    - app_version:string（app版本）
    - platform(平台，如Android 11等)
    - brand（品牌，如 三星）
    - country （客户端国家）必填
    - city （客户端城市）
    - timestamp:long（上传时间戳）必填

    其中 user_default 目前 只需要处理type=vpn_connection的数据，其 data 格式为JSON 字符串（获取需要先转化为json），这里user_default的vpn_connection类型的数据和reports数据同级别，即都是节点数据，只是数据的格式不同需要对应，数组中每项的字段如下
    vpn_status（必填）	连接状态	num	1是成功  2是失败  3是禁止连接
    prohibition_connection	禁止连接原因	num	（拓展用）1是流量总上限达到  2是账号异常  3是其他
    vpn_type（必填）	触发类型	num	1是连接 2是主动断开 3是重试 4是时间到了自动断开  5是流量超出自动断开
    vpn_error_msg	失败原因	str	失败原因文案，失败的时候传
    vpn_node_ip（必填）	连接的节点	str	节点的ip或者域名
    vpn_node_address（必填）	节点的地址，或者说国家地区	str	如US
    vpn_source（必填）	触发来源	num	1首页 2节点列表 3其他 
    vpn_user_time	使用时间	str	使用时间 时分秒
    vpn_user_traffic	使用流量	str	使用流量 MB
    my_user_id（必填，通过追加@apple.com可以获取系统中用户的email）	用户登录信息	str	登录的用户信息或者（设备唯一识别标识） 登录的优先用登录信息
    arise_timestamp（必填）	产生的时间戳	num	产生的时间戳

二、统计表

1. v3_user_report_summary (主要用于统计上报情况)
    id
    user_id
    app_id:string（app包名）
    app_version:string（app版本）
    country （客户端国家）
    date 来自 metadata 中的 timestamp, 转为UTC8
    hour 来自 metadata 中的 timestamp, 转为UTC8
    report_count 该小时内的上报次数, 每一条代表上报一次


2. v3_user_report_node_summary (主要用于统计节点情况)
    id
    date 来自 metadata 中的 timestamp, 转为UTC8
    hour 来自 metadata 中的 timestamp, 转为UTC8
    node_id 来自 reports 和 user_default（vpn_connection），从vpn_node_ip（同node_host）来获取node_id
    node_host vpn_node_ip转为node_host
    node_type 节点的协议类型
    probe_stage 来自reports, 其中user_default中的数据默认为post_connect_probe
    avg_delay 报节点的平均延迟，通过reports和user_default（vpn_connection）的数据计算，user_default中data的vpn_status为2的失败节点连接延迟默认为6000，成功默认为200
    traffic_usage 节点的流量使用，通过user_default（vpn_connection）的 vpn_user_traffic， reports默认为0
    traffic_use_time 节点使用时长，通过user_default（vpn_connection）的 vpn_user_time， reports默认为0
    compute_count 参与计算的数据总数（同纬度下）, reports 和  user_default（vpn_connection）的总数


3. v3_user_report_traffic (主要用于统计用户流量)
    id
    date 来自 metadata 中的 timestamp, 转为UTC8
    hour 来自 metadata 中的 timestamp, 转为UTC8
    user_id
    app_id:string（app包名）
    app_version:string（app版本）
    country （客户端国家）必填
    traffic_usage 节点的流量使用，通过user_default（vpn_connection）的 vpn_user_traffic， reports默认为0
    traffic_use_time 节点使用时长，通过user_default（vpn_connection）的 vpn_user_time， reports默认为0
    compute_count 总数

4. v3_user_report_node_fail (主要用于排查错误节点信息，仅保留一周数据)
    id
    node_id
    node_host
    node_type 节点的协议类型
    probe_stage 来自reports, 其中user_default（vpn_connection）中的数据默认为post_connect_probe
    error_code 来自report的error_code, user_default（vpn_connection）则取自 vpn_error_msg
    