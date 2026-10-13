# IVR Node Spec: `ivr_collect_input`

## What it does

Plays an audio prompt and collects a sequence of DTMF digits from the caller (e.g. a phone number, account number, or any numeric input). The collected digits are stored in a named session variable that downstream nodes can reference via `{{variable_name}}`.

The caller can press `#` to confirm entry before the max digit count is reached. If no input is received after all retries, the node routes to the timeout branch.

---

## Node type string

```
ivr_collect_input
```

---

## Config fields

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `audio_file_id` | integer (FK) | required | Audio prompt to play ("Please enter your phone number followed by #") |
| `variable` | string | required | Session variable name to store the result (e.g. `caller_phone`). Accessible as `{{caller_phone}}` in downstream nodes. |
| `num_digits` | integer | `11` | Maximum digits to collect before auto-confirming |
| `timeout` | integer | `10` | Inter-digit timeout in seconds (how long to wait between keystrokes) |
| `retries` | integer | `2` | How many times to replay the prompt if caller enters nothing |
| `terminate_on` | string | `#` | Digit that confirms entry early (stripped from result) |

---

## Output edges / routes

The node has exactly **two** output handles:

| Edge key | When fired | Typical use |
|----------|-----------|-------------|
| `dtmf` | Caller entered at least one digit | Continue flow with collected input |
| `timeout` | No digits received after all retries | Play error message / hangup |

> There is no per-digit routing (unlike `ivr_menu`). The full digit string is stored in the variable — routing only distinguishes got-input vs no-input.

---

## How the backend uses it

- `_buildInstruction()` returns `action: collect_input` to the AGI script along with all config fields
- When the AGI reports back `event: dtmf`, `_advanceFlow()` saves `event_data` (the digit string) into `variables[config.variable]` in the session before advancing
- Downstream nodes can interpolate `{{caller_phone}}` (or whatever variable name was configured) anywhere strings are accepted

---

## Suggested UI appearance

- **Category:** Input / Collection (same group as `ivr_menu`, `ivr_get_digits`)
- **Icon:** keyboard / dial-pad icon
- **Color:** same accent as other input nodes (e.g. blue/indigo)
- **Output handles:**
  - Green handle labelled `Got input` → `dtmf` edge
  - Red/orange handle labelled `No input` → `timeout` edge

---

## Config panel fields (Vue form)

```
[Audio Prompt]        <AudioFilePicker>   required
[Variable name]       <TextInput>         required  placeholder="caller_phone"
                                          hint: "Stored as {{caller_phone}} downstream"
[Max digits]          <NumberInput>       default=11  min=1  max=20
[Inter-digit timeout] <NumberInput>       default=10  min=3  max=30  suffix="sec"
[Retries]             <NumberInput>       default=2   min=1  max=5
[Terminate on]        <TextInput>         default="#"  maxlength=1
```

---

## Validation rules

- `audio_file_id` — required; must reference an existing audio file
- `variable` — required; alphanumeric + underscores only (`/^[a-z_][a-z0-9_]*$/i`); must not conflict with reserved variable names (`caller_id`, `did`, `customer_id`, `customer_name`)
- `num_digits` — 1–20
- `timeout` — 3–30
- `retries` — 1–5
- `terminate_on` — single character, one of `0-9*#`

---

## Example node config (JSON)

```json
{
  "id": "node_collect_phone",
  "type": "ivr_collect_input",
  "config": {
    "audio_file_id": 42,
    "variable": "caller_phone",
    "num_digits": 11,
    "timeout": 10,
    "retries": 2,
    "terminate_on": "#"
  },
  "next": null,
  "routes": {
    "dtmf":    "node_lookup_customer",
    "timeout": "node_sorry_hangup"
  }
}
```

---

## Related existing nodes for reference

- `ivr_menu` — single digit, routes per digit value
- `ivr_get_digits` — multi-digit but **does not** store result in a variable
- `ivr_set_variable` — silent node, sets a variable to a static/interpolated value
