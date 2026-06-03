# URL2AI RQDB4AI Jobs

URL2AI固有のjobコードはURL2AIリポジトリ配下に置く。

RQDB4AI本体にはURL2AI固有のPythonファイル、設定、説明を書かない。

## Job code

- `/home/kojima/work/url2ai/src/oss_jobs.py`
- `/home/kojima/work/url2ai/src/polymarket_jobs.py`
- `/home/kojima/work/url2ai/src/finreport_jobs.py`

## 方針

- RQDB4AIはキュー管理とPython callable実行だけを担当する。
- URL2AIの業務ロジックはURL2AI側が持つ。
- OSS、Polymarket、FinReportの生成・登録判断はURL2AI側の責務。
- enqueue成功を登録成功として扱わない。
- 実登録件数はURL2AI側の処理結果またはreportを正とする。
