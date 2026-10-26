-- Faculty members could never submit order feedback: order_feedback.student_id
-- was NOT NULL, but faculty reviews store the customer in faculty_id and leave
-- student_id NULL, so every faculty INSERT failed.
--
-- "Exactly one of student_id / faculty_id is set" is enforced in
-- public/orders.php. A CHECK constraint is not possible here because MySQL
-- forbids CHECKs on columns used by foreign keys with ON DELETE actions.
ALTER TABLE `order_feedback`
  MODIFY `student_id` int UNSIGNED DEFAULT NULL;
