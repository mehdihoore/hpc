(defun C:HatchToBoundary (/ ss i ename oldecho oldlayer)
  (princ "\nSelect HATCH objects to create boundaries from: ")
  (setq ss (ssget '((0 . "HATCH")))) ; Filter for HATCH entities only

  (if ss
    (progn
      (setq oldecho (getvar "CMDECHO"))
      (setvar "CMDECHO" 0) ; Turn off command echoing for cleaner execution

      (setq i 0)
      (repeat (sslength ss)
        (setq ename (ssname ss i))
        (if ename
          (progn
            (princ (strcat "\nProcessing hatch: " (cdr (assoc -1 (entget ename))))) ; Optional: print entity name
            ;; Use HATCHEDIT command to recreate the boundary
            ;; _B for Boundary option
            ;; _P for Polyline type
            ;; _N for No, do not associate hatch with new boundary (safer, just creates the polyline)
            (command "_.HATCHEDIT" ename "_B" "_P" "_N")
            (princ " -> Boundary created.")
          )
        )
        (setq i (1+ i))
      )
      (setvar "CMDECHO" oldecho) ; Restore command echoing
      (princ (strcat "\nFinished. " (itoa (sslength ss)) " hatch(es) processed."))
    )
    (princ "\nNo hatch objects selected.")
  )
  (princ) ; Suppress returning the last value
)

(princ "\nLISP routine HatchToBoundary loaded. Type HTB or HatchToBoundary to run.")
(princ)