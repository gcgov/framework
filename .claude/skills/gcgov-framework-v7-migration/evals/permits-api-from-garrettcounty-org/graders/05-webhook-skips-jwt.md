---
type: llm
focus: trace
weight: 1
---

The PayJunction webhook route still skips the JWT guard under v7: the route sets authentication false, or app/router.php implements gcgov\framework\interfaces\router\skipsServiceAuthentication.
