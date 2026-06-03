# URL2AI RQDB4AI Result Rule

URL2AI jobs must return the same common result shape used by every RQDB4AI job.

```json
{
  "ok": true,
  "status": "ok",
  "items": 3,
  "metrics": {"created": 3},
  "note": "short summary",
  "artifacts": [],
  "error": null
}
```

Rules:

- `items` is the dashboard count.
- `metrics` contains details such as `created`, `top_n`, `period`, or matched counts.
- RQDB4AI must not parse URL2AI stdout or add URL2AI-specific dashboard logic.
- Job wrappers may keep old fields temporarily, but the dashboard must depend only on the common fields.
