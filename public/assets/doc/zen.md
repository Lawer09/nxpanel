# CreateZecInstances

## 1. 接口描述

本接口(CreateZecInstances)用于创建一台或多台虚拟机实例。

#### 准备工作

* 查询机型规格：调用[`DescribeZoneInstanceConfigInfos`](https://docs.console.zenlayer.com/api-reference/cn/compute/zec/instance/describezoneinstanceconfiginfos) 可以查询到规格信息。
* 查询镜像：调用[`DescribeImages`](https://docs.console.zenlayer.com/api-reference/cn/compute/zec/image/describeimages)可以查询到镜像信息。
* 查询密钥对：调用 [`DescribeKeyPairs`](https://docs.console.zenlayer.com/api-reference/cn/security/ccs/key-pair/describekeypairs)可以查询到密钥对ID信息。

{% hint style="info" %}
**注意事项**

* 实例创建成功后将自动开机启动，实例状态变为`RUNNING`, 如果创建失败，状态会变为`CREATE_FAILED`。
* 购买时需要确保账户账号状态正常。
* 调用本接口创建实例，支持代金券自动抵扣，详情请参考代金券选用规则。
* 本接口为异步接口，当创建实例请求下发成功后会返回一个实例`ID`列表，此时创建实例操作并未立即完成。在此期间实例的状态将会处于`DEPLOYING`，实例创建结果可以通过调用[`DescribeInstances`](https://docs.console.zenlayer.com/api-reference/cn/compute/zec/instance/describeinstances) 接口查询，如果实例状态由`DEPLOYING`变为`RUNNING`则代表创建成功，如果变为`CREATE_FAILED`则代表创建失败，创建过程中不可对实例进行任何操作。
* 单次最多能创建**100**台实例。
  {% endhint %}

## 2. 请求参数

以下请求参数列表仅列出了接口中需要的请求参数

| 参数名称               | 必选 | 类型                                                                                                                | 描述                                                                                                                                                                                                                                                                                                                                                                                 |
| ------------------ | -- | ----------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| zoneId             | 是  | String                                                                                                            | 可用区ID。                                                                                                                                                                                                                                                                                                                                                                             |
| imageId            | 是  | String                                                                                                            ||
| instanceType       | 是  | String                                                                                                            ||
| instanceCount      | 是  | Integer                                                                                                           | <p>要创建的实例数量。</p><p>可选值范围：\[1, 100]</p><p>默认值：1</p>                                                                                                                                                                                                                                                                                                                                 |
| subnetId           | 是  | String                                                                                                            | 子网ID。                                                                                                                                                                                                                                                                                                                                                                              |
| timeZone           | 否  | String                                                                                                            | 设置操作系统的时区。                                                                                                                                                                                                                                                                                                                                                                         |
| instanceName       | 否  | String                                                                                                            |  |
| password           | 否  | String                                                                                                            | |
| keyId              | 否  | String                                                                                                            |                                                                                                                                   |
| nicNetworkType     | 否  | [NicNetworkType](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#nicnetworktype)         | <p>网卡模式。</p><p>默认值：Auto</p>                                                                                                                                                                                                                                                                                                                                                        |
| systemDisk         | 否  | [SystemDisk](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#systemdisk)                 | <p>实例系统盘配置信息。</p><p>若不指定该参数，则按照系统默认值进行分配。</p><p>即操作系统要求的最小大小。</p>                                                                                                                                                                                                                                                                                                                  |
| dataDisks          | 否  | Array of [DataDisk](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#datadisk)            | <p>实例数据盘配置信息。</p><p>若不指定该参数，则默认不额外购买数据盘。</p><p>目前只能附带1个数据盘。</p>                                                                                                                                                                                                                                                                                                                    |
| securityGroupId    | 否  | String                                                                                                            | <p>要配置在实例主网卡的安全组ID。</p><p>目前只能关联1个安全组。</p><p>如果未指定，会默认用VPC关联的安全组。</p>                                                                                                                                                                                                                                                                                                              |
| lanIp              | 否  | String                                                                                                            | <p>分配的内网起始IP。</p><p>如果内网IP被使用,则会往后分配。</p>                                                                                                                                                                                                                                                                                                                                          |
| enableAgent        | 否  | Boolean                                                                                                           | <p>是否安装启动Agent。</p><p>默认值：true</p>                                                                                                                                                                                                                                                                                                                                                 |
| enableIpForward    | 否  | Boolean                                                                                                           | <p>是否开启IP转发。</p><p>默认值：false</p>                                                                                                                                                                                                                                                                                                                                                   |
| internetChargeType | 否  |                                                                                                                                                                                                                                               |
| trafficPackageSize | 否  | Float                                                                                                             |                                                                                                                                      |
| bandwidth          | 否  | Integer                                                                                                           |                                                                                                                                                                                                                                                         |
| eipBindType        | 否  | [BindType](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#bindtype)                     | <p>公网IP的绑定模式。</p><p>当分配公网IP时需要指定。</p><p>默认值：FullNat</p>                                                                                                                                                                                                                                                                                                                            |
| eipV4Type \[已废弃]   | 否  | [EipNetworkType](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#eipnetworktype)         | <p>公网IPv4的线路类型。</p><p>当分配公网IP时需要指定。</p><p>请确保所选子网的堆栈类型支持<code>IPv4</code>。</p><p>目前不支持三线IP随实例一起创建。</p><p>已废弃，请使用<code>networkLineType</code>。</p>                                                                                                                                                                                                                                  |
| ipStackType        | 否  | [SubnetStackType](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#subnetstacktype)       | <p>设置IP堆栈类型。</p><p>如果不指定，当子网堆栈类型IPv4或IPv4\_IPv6时，默认使用IPv4。</p>                                                                                                                                                                                                                                                                                                                     |
| networkLineType    | 否  | [NetworkLineType](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#networklinetype)       | <p>公网IPv4的线路类型。</p><p>当分配公网IP时需要指定。</p><p>请确保所选子网的堆栈类型支持<code>IPv4</code>。</p><p>目前不支持三线IP随实例一起创建。</p>                                                                                                                                                                                                                                                                             |
| clusterId          | 否  | String                                                                                                            | <p>共享带宽包ID。</p><p>当网络计费方式是共享带宽包计费(<code>BandwidthCluster</code>)时需要指定。</p>                                                                                                                                                                                                                                                                                                         |
| ipv6ClusterId      | 否  | String                                                                                                            |                                                                                                                                                   |
| resourceGroupId    | 否  | String                                                                                                            | 创建后实例所在的资源组ID，如不指定则放入默认资源组。                                                                                                                                                                                                                                                                                                                                                        |
| marketingOptions   | 否  | [MarketingInfo](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#marketinginfo)           | 市场营销的相关选项。                                                                                                                                                                                                                                                                                                                                                                         |
| tags               | 否  | [TagAssociation](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#tagassociation)         | <p>创建实例时关联的标签。</p><p>注意：·关联<code>标签键</code>不能重复。</p>                                                                                                                                                                                                                                                                                                                               |
| userData           | 否  | String                                                                                                            | 初始化命令。                                                                                                                                                                                                                                                                                                                                                                             |
| instanceOptions    | 否  | [InstanceOptions](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#instanceoptions)       | 实例选项配置。                                                                                                                                                                                                                                                                                                                                                                            |

## 3. 响应结果

| 参数名称          | 类型                                                                                                                     | 描述                                                       |
| ------------- | ---------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| requestId     | String                                                                                                                 | <p>唯一请求 ID。</p><p>每次请求都会返回。定位问题时需要提供该次请求的 requestId。</p> |
| orderNumber   | String                                                                                                                 | 订单编号。                                                    |
| instanceIdSet | Array of String                                                                                                        | 虚拟机实例ID列表。                                               |
| instances     | Array of [DiskWithInstance](https://docs.console.zenlayer.com/api-reference/cn/compute/datastructure#diskwithinstance) | <p>随机器创建的数据盘id集合。</p><p>如果请求中没有指定数据盘，返回空数组。</p>          |

## 4. 代码示例

{% tabs %}
{% tab title="示例" %}
**1. 使用最简单的参数创建实例。不分配公网IP地址。**

```json
POST /api/v2/zec HTTP/1.1
Host: console.zenlayer.com
Content-Type: application/json
X-ZC-Action: CreateZecInstances
<Common Request Params>

Request：
{
  "zoneId": "asia-east-1a",
  "instanceType": "z2a.cpu.1",
  "imageId": "ubuntu2404_20240712",
  "instanceName": "Test-InstanceName",
  "keyId": "key-rcfljdP5",
  "subnetId": "1272168087751233112",
  "nicNetworkType": "Auto",
  "tags":
    {
      "tags": [
        {
          "key": "key1",
          "value": "value1"
        },
        {
          "key": "key2",
          "value": "value2"
        }
      ]
    }
}

Response:
{
  "requestId": "TA471524B-84E1-467B-AB77-75387BBD190B",
  "response": {
    "requestId": "TA471524B-84E1-467B-AB77-75387BBD190B",
    "instanceIdSet": [
      "<instanceId>"
    ],
    "instances": [
    ],
    "orderNumber": "<orderNumber>"
  }
}
```

**2. 创建2台实例，分配公网IP。线路类型为BGP, 网络计费使用共享带宽包。带宽限速为10Mbps。**

```json
POST /api/v2/zec HTTP/1.1
Host: console.zenlayer.com
Content-Type: application/json
X-ZC-Action: CreateZecInstances
<Common Request Params>

Request：
{
  "zoneId": "asia-east-1a",
  "instanceType": "z2a.cpu.1",
  "imageId": "ubuntu2404_20240712",
  "instanceName": "Test-InstanceName",
  "keyId": "key-rcfljdP5",
  "subnetId": "1272168087751233112",
  "internetChargeType": "BandwidthCluster",
  "bandwidth": 10,
  "clusterId": "<clusterId>",
  "networkLineType": "PremiumBGP"
}

Response:
{
  "requestId": "TA471524B-84E1-467B-AB77-75387BBD190B",
  "response": {
    "requestId": "TA471524B-84E1-467B-AB77-75387BBD190B",
    "instanceIdSet": [
      "<instanceId>"
    ],
    "instances": [
    ],
    "orderNumber": "<orderNumber>"
  }
}
```

{% endtab %}
{% endtabs %}

## 5. 开发者工具


## 6. 错误码

下面包含业务逻辑中遇到的错误码，其他错误码见[公共错误码](https://docs.console.zenlayer.com/api-reference/cn/api-introduction/instruction/commonerrorcode)

| HTTP状态码 | 错误码                                                             | 说明                      |
| ------- | --------------------------------------------------------------- | ----------------------- |
| 400     | INVALID\_DATA\_DISK\_COUNT\_LIMITATION                          | 数据盘的数量超出限制。             |
| 400     | INVALID\_DISK\_CATEGORY\_TYPE                                   | 云盘的类型不合法。               |
| 400     | INVALID\_DISK\_ILLEGAL\_AMOUNT                                  | 数据盘的数量超过限制。             |
| 404     | INVALID\_EIP\_NOT\_FOUND                                        | EIP不存在。                 |
| 404     | INVALID\_GPU\_INSTANCE\_TYPE\_NOT\_FOUND                        | GPU实例规格不存在。             |
| 400     | INVALID\_IMAGE\_AGENT\_NOT\_SUPPORT                             | 镜像不支持Agent。             |
| 400     | INVALID\_IMAGE\_IPV6\_NOT\_SUPPORT                              | 镜像不支持IPv6 Only。         |
| 400     | INVALID\_IMAGE\_KEY\_PAIR\_NOT\_SUPPORT                         | 镜像不支持ssh密钥对。            |
| 404     | INVALID\_IMAGE\_NOT\_FOUND                                      | 镜像不存在。                  |
| 400     | INVALID\_IMAGE\_PASSWORD\_NOT\_SUPPORT                          | 操作系统不支持指定密码。            |
| 400     | INVALID\_IMAGE\_SIZE\_EXCEED                                    | 镜像大小超过指定系统盘的大小。         |
| 400     | INVALID\_INSTANCE\_COUNT\_LIMITATION                            | 实例的数量超过配额限制。            |
| 404     | INVALID\_INSTANCE\_TYPE\_NOT\_FOUND                             | 实例规格不存在。                |
| 400     | INVALID\_IP\_BROADCAST\_ADDRESS                                 | IP为广播地址不可用。             |
| 400     | INVALID\_IP\_FIRST\_ADDRESS                                     | IP为网关地址不可用。             |
| 400     | INVALID\_IP\_NETWORK\_ADDRESS                                   | IP为网络地址不可用。             |
| 400     | INVALID\_IP\_OUT\_OF\_RANGE                                     | IP地址不合法，不属于CIDR范围。      |
| 404     | INVALID\_KEY\_PAIR\_NOT\_FOUND                                  | SSH密钥对不存在。              |
| 400     | INVALID\_LOGIN\_SETTING\_CONFLICT                               | 密码和密钥对不能同时设置。           |
| 409     | INVALID\_NIC\_INSTANCE\_REGION\_MISMATCH                        | 实例和网卡不在同一个节点。           |
| 400     | INVALID\_PARAMETER\_INSTANCE\_NAME\_EXCEED                      | 实例名称超过长度限制。             |
| 400     | INVALID\_PARAMETER\_INSTANCE\_NAME\_EXCEED\_MINIMUM\_LENGTH     | 实例名称长度小于最小限制。           |
| 400     | INVALID\_PARAMETER\_INSTANCE\_NAME\_MALFORMED                   | 实例名称的格式不合法。             |
| 400     | INVALID\_PARAMETER\_USER\_DATA\_EXCEED                          | 实例命令超过长度限制。             |
| 404     | INVALID\_PASSWORD\_KEY\_PAIR\_MISSING                           | 未指定密码或密钥对。              |
| 400     | INVALID\_PASSWORD\_MALFORMED                                    | 密码格式错误。                 |
| 404     | INVALID\_REGION\_NOT\_FOUND                                     | 指定的可用区不存在。              |
| 400     | INVALID\_REGION\_ZONE\_MISMATCH                                 | 指定的Zone不在指定的Region下。    |
| 404     | INVALID\_SECURITY\_GROUP\_NOT\_FOUND                            | 安全组不存在。                 |
| 409     | INVALID\_SUBNET\_IPV4\_INSUFFICIENT                             | Subnet下可用的IPv4数量不足。     |
| 409     | INVALID\_SUBNET\_IPV6\_INSUFFICIENT                             | Subnet下可用的IPv6数量不足。     |
| 404     | INVALID\_SUBNET\_NOT\_FOUND                                     | 子网不存在。                  |
| 400     | INVALID\_SYSTEM\_DISK\_EXCEED\_LIMIT                            | 系统盘大小超过限制。              |
| 404     | INVALID\_ZONE\_NOT\_FOUND                                       | 可用区不存在。                 |
| 400     | OPERATION\_DENIED\_CPU\_ILLEGAL                                 | 规格的CPU数量非法。             |
| 400     | OPERATION\_DENIED\_DISK\_IO\_BURST                              | 未开启云盘性能突发功能。            |
| 400     | OPERATION\_DENIED\_EIP\_INSTANCE\_NOT\_ADAPTER                  | 指定的实例数量与需要创建的EIP数量不一致。  |
| 400     | OPERATION\_DENIED\_EIP\_INSUFFICIENT                            | 公网IP的库存不足，无法操作。         |
| 400     | OPERATION\_DENIED\_EIP\_IS\_DEFAULT                             | 默认公网IP无法解绑。             |
| 400     | OPERATION\_DENIED\_EIP\_IS\_NOT\_UN\_ASSIGN                     | EIP状态未解绑。               |
| 400     | OPERATION\_DENIED\_EIP\_NOT\_ASSIGNED                           | EIP状态未绑定。               |
| 400     | OPERATION\_DENIED\_EIP\_NOT\_SUPPORT\_PASS\_THROUGH\_BIND\_TYPE | EIP模式不支持高速模式。           |
| 400     | OPERATION\_DENIED\_EIP\_QUOTA\_LIMIT\_EXCEEDED                  | EIP数量超过配额限制。            |
| 400     | OPERATION\_DENIED\_EIP\_UNSUPPORT\_NETWORK\_TYPE                | EIP网络计费方式不支持。           |
| 400     | OPERATION\_DENIED\_INSTANCE\_TYPE\_FOR\_WINDOWS                 | 实例规格不适用于windows镜像类型的机器。 |
| 400     | OPERATION\_DENIED\_MEMORY\_AMOUNT\_ILLEGAL                      | 规格的MEMORY数量非法。          |
| 400     | OPERATION\_DENIED\_NIC\_NETWORK\_TYPE\_NOT\_SUPPORT             | 网卡模式不支持。                |
| 400     | OPERATION\_DENIED\_STACK\_TYPE\_NOT\_SUPPORT                    | 堆栈类型不支持。                |
| 400     | OPERATION\_DENIED\_SUBNET\_TYPE\_NOT\_SUPPORT                   | 子网堆栈类型不支持。              |
| 400     | OPERATION\_DENIED\_SUBNET\_TYPE\_NOT\_SUPPORT\_IPV4             | 子网堆栈类型不包括IPv4。          |
| 400     | OPERATION\_DENIED\_SUBNET\_ZONE\_MISMATCH                       | 子网的区域和可用区的区域不一致。        |
| 400     | STOCK\_INSUFFICIENT                                             | 库存不足。                   |
| 400     | UNSUPPORT\_SUBNET\_TYPE\_FOR\_INTERNET\_CHARGE\_TYPE            | 子网堆栈类型不支持公网。            |
| 400     | INVALID\_IP\_STACK\_TYPE\_NOT\_SUPPORT                          | IP堆栈类型不支持。              |
| 400     | INVALID\_NESTED\_VIRTUALIZATION\_NOT\_ALLOWED                   | 嵌套虚拟化配置不被允许。            |
