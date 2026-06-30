# 椤圭洰鑱氬悎鎶ヨ〃鏌ヨ鎺ュ彛锛圕amelCase锛?
鏈枃涓哄墠绔鎺ャ€岄」鐩棩鑱氬悎琛?`project_daily_aggregates`銆嶆帴鍙ｈ鏄庯紝鍙傛暟涓庤繑鍥炵粺涓€浣跨敤椹煎嘲鍐欐硶銆?
## 1. 鍩虹淇℃伅

- 鍓嶇紑锛歚/api/v3/{securePath}`
- 杩斿洖鏍煎紡锛?
```json
{
  "code": 0,
  "msg": "鎿嶄綔鎴愬姛",
  "data": {}
}
```

---

## 2. 鏃ユ姤鏄庣粏鏌ヨ锛堜富鎺ュ彛锛?
`GET /project-aggregates/daily`

### Query 鍙傛暟

| 鍙傛暟 | 绫诲瀷 | 蹇呭～ | 璇存槑 |
| --- | --- | --- | --- |
| startDate | string | 鏄?| 寮€濮嬫棩鏈燂紝`YYYY-MM-DD` |
| endDate | string | 鏄?| 缁撴潫鏃ユ湡锛宍YYYY-MM-DD` |
| projectCode | string | 鍚?| 椤圭洰浠ｅ彿 |
| country | string | 鍚?| 鍥藉锛堢┖鍊肩粺涓€鎸?`XX` 澶勭悊锛?|
| groupBy | string[] | 鍚?| 鎸夌淮搴﹁仛鍚堬紝鏀寔 `reportDate` / `projectCode` / `country`锛岄粯璁ゆ槑缁?|
| page | int | 鍚?| 榛樿 `1` |
| pageSize | int | 鍚?| 榛樿 `50`锛屾渶澶?`200` |
| orderBy | string | 鍚?| 榛樿 `reportDate`锛屾敮鎸侊細`reportDate` / `projectCode` / `country` / `newUsers` / `reportNewUsers` / `fbNewUsers` / `dauUsers` / `fbDauUsers` / `adRevenue` / `adSpendCost` / `trafficCost` / `totalCost` / `trafficCostRatio` / `profit` / `roi` / `adSpendCpi` / `updatedAt` |
| orderDir | string | 鍚?| `asc` / `desc`锛岄粯璁?`desc` |

`groupBy` 瀹氫箟锛?
- 涓嶄紶鎴栦紶绌烘暟缁勶細鏄庣粏锛堟寜 `reportDate + projectCode + country` 鍘熷绮掑害锛?- 浼犳暟缁勶細鎸夋暟缁勪腑鐨勭淮搴︾粍鍚堣仛鍚堬紝渚嬪锛?  - `['reportDate', 'projectCode']`
  - `['reportDate', 'projectCode', 'country']`

璇存槑锛?
- 浠呮敮鎸?`groupBy`锛堥┘宄帮級鍙傛暟鍚?- 浠呮敮鎸佹暟缁勫舰寮?`groupBy`

### data 杩斿洖

```json
{
  "data": [
    {
      "id": 1,
      "reportDate": "2026-04-28",
      "projectCode": "A003",
      "country": "US",
      "dauUsers": 220,
      "newUsers": 36,
      "reportNewUsers": 12,
      "fbNewUsers": 28,
      "adRevenue": "344.340000",
      "fbDauUsers": 198,
      "adRequests": 120000,
      "adMatchedRequests": 108000,
      "adImpressions": 98000,
      "adClicks": 5100,
      "adEcpm": "3.513673",
      "adCtr": "5.204082",
      "adMatchRate": "90.000000",
      "adShowRate": "90.740741",
      "adSpendCost": "210.000000",
      "adSpendCpi": "5.833333",
      "adSpendCpc": "0.041176",
      "adSpendCpm": "2.142857",
      "trafficUsageMb": "53555.200000",
      "trafficCost": "8.368000",
      "totalCost": "218.368000",
      "trafficCostRatio": "0.038321",
      "profit": "125.972000",
      "roi": "1.580370",
      "updatedAt": "2026-04-28 10:05:02"
    }
  ],
  "total": 1,
  "page": 1,
  "pageSize": 50
}
```

---

## 2.1 鎵嬪姩瑙﹀彂鑱氬悎锛堟棩鏈熻寖鍥达級

`POST /project-aggregates/aggregate`

### Body 鍙傛暟

| 鍙傛暟 | 绫诲瀷 | 蹇呭～ | 璇存槑 |
| --- | --- | --- | --- |
| startDate | string | 鏄?| 寮€濮嬫棩鏈燂紝`YYYY-MM-DD` |
| endDate | string | 鏄?| 缁撴潫鏃ユ湡锛宍YYYY-MM-DD` |
| projectId | int | 鍚?| 椤圭洰 ID锛涗紶鍏ュ悗浠呴噸绠楄椤圭洰锛屼笉褰卞搷鍚屾棩鏈熷叾浠栭」鐩仛鍚堢粨鏋?|

### 绀轰緥璇锋眰

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "projectId": 12
}
```

### data 杩斿洖

```json
{
  "success": true,
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "projectId": 12,
  "exitCode": 0,
  "output": "Start aggregating project daily data..."
}
```

说明：该接口会同步调用 `project:aggregate-daily --start-date --end-date`；传入 `projectId` 时会追加 `--project-id`，并且只删除/重建该项目的日报聚合结果；`project_report_hourly` 由独立命令 `project:aggregate-hourly` 和管理端 `/projects/aggregate-hourly` 接口维护。
---

## 2.2 鎵嬪姩瑙﹀彂鑱氬悎锛堝紓姝ワ級

`POST /project-aggregates/aggregate-async`

### Body 鍙傛暟

| 鍙傛暟 | 绫诲瀷 | 蹇呭～ | 璇存槑 |
| --- | --- | --- | --- |
| startDate | string | 鏄?| 寮€濮嬫棩鏈燂紝`YYYY-MM-DD` |
| endDate | string | 鏄?| 缁撴潫鏃ユ湡锛宍YYYY-MM-DD` |
| projectId | int | 鍚?| 椤圭洰 ID锛涗紶鍏ュ悗浠呭紓姝ラ噸绠楄椤圭洰锛屼笉褰卞搷鍚屾棩鏈熷叾浠栭」鐩仛鍚堢粨鏋?|

### 绀轰緥璇锋眰

```json
{
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "projectId": 12
}
```

### data 杩斿洖

```json
{
  "accepted": true,
  "triggerId": "7f517a8a-7f56-4d4f-a0cf-1649bc1f4af9",
  "startDate": "2026-04-01",
  "endDate": "2026-04-28",
  "projectId": 12,
  "status": "queued"
}
```

璇存槑锛?- 璇ユ帴鍙ｄ粎鎶曢€掗槦鍒椾换鍔″苟绔嬪嵆杩斿洖
- 浼犲叆 `projectId` 鏃讹紝闃熷垪浠诲姟浼氶€忎紶鍒?`project:aggregate-daily --project-id`
- 闇€纭繚闃熷垪娑堣垂鑰呭凡鍚姩锛堝 `php artisan queue:work`锛?- 浠诲姟鎵ц鏃ュ織鍙€氳繃 `triggerId` 鍦ㄦ棩蹇椾腑妫€绱?
---

## 3. 姹囨€绘煡璇?
`GET /project-aggregates/summary`

### Query 鍙傛暟

| 鍙傛暟 | 绫诲瀷 | 蹇呭～ | 璇存槑 |
| --- | --- | --- | --- |
| startDate | string | 鏄?| 寮€濮嬫棩鏈燂紝`YYYY-MM-DD` |
| endDate | string | 鏄?| 缁撴潫鏃ユ湡锛宍YYYY-MM-DD` |
| projectCode | string | 鍚?| 椤圭洰浠ｅ彿 |
| country | string | 鍚?| 鍥藉 |
| groupBy | string | 鍚?| 鑱氬悎缁村害锛歚project` / `country` / `date`锛岄粯璁?`project` |

### data 杩斿洖

```json
[
  {
    "projectCode": "A003",
    "newUsers": 380,
    "reportNewUsers": 120,
    "fbNewUsers": 310,
    "dauUsers": 2450,
    "fbDauUsers": 2010,
    "adRevenue": "5230.330000",
    "adRequests": 1860000,
    "adMatchedRequests": 1670000,
    "adImpressions": 1490000,
    "adClicks": 78800,
    "adEcpm": "3.510289",
    "adCtr": "5.288591",
    "adMatchRate": "89.784946",
    "adShowRate": "89.221557",
    "adSpendCost": "3100.000000",
    "adSpendCpi": "8.157895",
    "adSpendCpc": "0.039340",
    "adSpendCpm": "2.080537",
    "trafficUsageMb": "782344.120000",
    "trafficCost": "122.241269",
    "totalCost": "3222.241269",
    "trafficCostRatio": "0.037937",
    "profit": "2008.088731",
    "roi": "1.624269"
  }
]
```

---

## 4. 瓒嬪娍鏌ヨ

`GET /project-aggregates/trend`

### Query 鍙傛暟

| 鍙傛暟 | 绫诲瀷 | 蹇呭～ | 璇存槑 |
| --- | --- | --- | --- |
| startDate | string | 鏄?| 寮€濮嬫棩鏈燂紝`YYYY-MM-DD` |
| endDate | string | 鏄?| 缁撴潫鏃ユ湡锛宍YYYY-MM-DD` |
| projectCode | string | 鍚?| 椤圭洰浠ｅ彿 |
| country | string | 鍚?| 鍥藉 |
| dimension | string | 鍚?| 鏃堕棿缁村害锛歚day` / `month`锛岄粯璁?`day` |

### data 杩斿洖

```json
[
  {
    "time": "2026-04-28",
    "newUsers": 36,
    "reportNewUsers": 12,
    "fbNewUsers": 28,
    "dauUsers": 220,
    "fbDauUsers": 198,
    "adRevenue": "344.340000",
    "adSpendCost": "210.000000",
    "adSpendCpi": "5.833333",
    "trafficUsageMb": "53555.200000",
    "trafficCost": "8.368000",
    "totalCost": "218.368000",
    "trafficCostRatio": "0.038321",
    "profit": "125.972000",
    "roi": "1.580370"
  }
]
```

---

## 5. 瀛楁璇存槑锛堢粺涓€ CamelCase锛?
- `reportDate`: 鏃ユ湡
- `projectCode`: 椤圭洰浠ｅ彿
- `country`: 鍥藉锛堢┖鍊肩粺涓€褰掍竴涓?`XX`锛?- `dauUsers`: 娲昏穬鐢ㄦ埛鏁?- `newUsers`: 鏂板鐢ㄦ埛鏁?- `reportNewUsers`: 涓婃姤鏂板鐢ㄦ埛鏁帮紙棣栨涓婃姤鏃ユ湡涓哄綋鏃ョ殑鍘婚噸鐢ㄦ埛鏁帮紝鏉ユ簮 `v3_user_report_count`锛?- `fbNewUsers`: Firebase 鏂板鐢ㄦ埛鏁帮紙鏉ユ簮 `firebase_report_user_summary.new_user_count`锛?- `adRevenue`: 骞垮憡鏀跺叆
- `adRequests`: 璇锋眰鏁?- `adMatchedRequests`: 鍖归厤鏁?- `adImpressions`: 灞曠ず閲?- `adClicks`: 鐐瑰嚮閲?- `adEcpm`: eCPM
- `adCtr`: CTR
- `adMatchRate`: 鍖归厤鐜?- `adShowRate`: 灞曠ず鐜?- `adSpendCost`: 骞垮憡鎶曟斁鎴愭湰
- `adSpendCpi`: CPI锛坄adSpendCost / newUsers`锛?- `adSpendCpc`: CPC锛坄adSpendCost / 鎶曟斁鐐瑰嚮鏁癭锛屾潵婧?`ad_spend_platform_daily_reports.clicks`锛?- `adSpendCpm`: CPM锛坄adSpendCost * 1000 / 鎶曟斁灞曠ず鏁癭锛屾潵婧?`ad_spend_platform_daily_reports.impressions`锛?- `trafficUsageMb`: 浠ｇ悊娴侀噺浣跨敤閲忥紙MB锛?- `trafficCost`: 浠ｇ悊娴侀噺鎴愭湰锛坄trafficUsageMb * 0.16 / 1024`锛?- `totalCost`: 鎬绘垚鏈紙`adSpendCost + trafficCost`锛?- `trafficCostRatio`: 娴侀噺鎴愭湰鍗犳瘮锛坄trafficCost / totalCost`锛屽綋 `totalCost` 涓?0 鏃惰繑鍥?`null`锛?- `profit`: 姣涘埄锛坄adRevenue - adSpendCost - trafficCost`锛?- `roi`: ROI锛坄adRevenue / (adSpendCost + trafficCost)`锛?- `updatedAt`: 鏇存柊鏃堕棿
- `fbDauUsers`: Firebase 鏃ユ椿鐢ㄦ埛鏁帮紙鏉ユ簮 `firebase_report_user_summary.dau_device_count`锛?
