import json;
import sys;
from deepface import DeepFace;

try:
    result = DeepFace.verify(
        img1_path = "{{img1_path}}",
        img2_path = "{{img2_path}}",
        enforce_detection = {{enforce_detection}},
        anti_spoofing={{anti_spoofing}},
        align = {{align}},
        model_name = "{{model_name}}",
        detector_backend = "{{detector_backend}}",
        distance_metric = "{{distance_metric}}",
        normalization = "{{normalization}}"
    );

    print(json.dumps(result, default=str))
except ValueError as e:
    print(json.dumps({"error": str(e)}), file=sys.stderr)
