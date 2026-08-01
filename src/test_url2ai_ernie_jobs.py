import unittest
from unittest.mock import Mock, patch

from url2ai_ernie_jobs import generate_image_job


class ErnieJobsTest(unittest.TestCase):
    def test_returns_valid_ernie_payload(self):
        response = Mock(status_code=200)
        response.json.return_value = {"ok": True, "image_base64": "abc", "width": 256, "height": 256}
        with patch("url2ai_ernie_jobs.requests.post", return_value=response) as post:
            result = generate_image_job({"prompt": "test", "width": 256, "height": 256})
        self.assertEqual(result["image_base64"], "abc")
        post.assert_called_once()

    def test_rejects_empty_prompt_before_http(self):
        with self.assertRaises(RuntimeError):
            generate_image_job({"prompt": ""})


if __name__ == "__main__":
    unittest.main()
